<?php

namespace Civi\Osdi\LocalObject;

use Civi\Api4\Generic\AbstractAction;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\LocalObjectInterface;
use Civi\Osdi\Result\Diff as DiffResult;
use Civi\Osdi\Result\Save as SaveResult;

abstract class AbstractLocalObject implements LocalObjectInterface {

  public Field $id;

  protected bool $isTouched = FALSE;

  protected bool $isLoaded = FALSE;

  protected bool $_isInitialized = FALSE;

  public function __construct(int $idValue = NULL) {
    $this->initializeFields($idValue);
  }

  public function __clone() {
    foreach ($this->getFieldMetadata() as $name => $metadata) {
      $this->$name = clone $this->$name;
      $this->$name->setBundle($this);
    }
  }

  public function __debugInfo(): array {
    if (!$this->_isInitialized) {
      return [];
    }
    try {
      return $this->getAllWithoutLoading();
    }
    catch (\Throwable $e) {
      return ['[could not render object for debugging]', __CLASS__, __FUNCTION__];
    }
  }

  /**
   * @return array [camelName => [fieldMetaData], ...]
   */
  abstract protected static function getFieldMetadata(): array;

  public static function getJoins(): array {
    return [];
  }

  public static function getOrderBys(): array {
    return [];
  }

  public static function fromArray(array $array): self {
    $object = new static();
    return $object->loadFromArray($array);
  }

  public static function fromId(string $id): self {
    $object = new static();
    $object->setId($id);
    return $object->load();
  }

  public function delete(): ?\Civi\Api4\Generic\Result {
    if (empty($id = $this->getId())) {
      return NULL;
    }

    \Civi::$statics['osdiClient.inProgress']['delete'][] = $this;
    $key = array_key_last(\Civi::$statics['osdiClient.inProgress']['delete']);

    try {
      /** @var \Civi\Api4\Generic\DAODeleteAction $deleteAction */
      $deleteAction = $this->makeApi4Action('delete');
      return $deleteAction
        ->addWhere('id', '=', $id)
        ->execute();
    }

    finally {
      unset(\Civi::$statics['osdiClient.inProgress']['delete'][$key]);
    }
  }

  public static function getSelects(): array {
    foreach (static::getFieldMetadata() as $camelName => $fieldMetaData) {
      if (array_key_exists('select', $fieldMetaData)) {
        $selects[$fieldMetaData['select']] = $camelName;
      }
    }
    return $selects ?? [];
  }

  /**
   * Compare loaded values with current values and report which, if any, are
   * different. The empty values NULL, '', and [] are all considered equivalent;
   * comparison of non-empty values is strict (type-sensitive).
   */
  public function diffChanges(): DiffResult {
    if (!$this->isTouched()) {
      $vals = $this->getAllWithoutLoading();
      return new DiffResult($vals, $vals, [], [], []);
    }

    $leftVals = $this->isLoaded() ? $this->getAllAsLoaded() : [];
    $rightVals = $this->getAllWithoutLoading();
    $leftOnly = $rightOnly = $different = [];

    foreach ($rightVals as $fieldName => $rightVal) {
      $leftVal = $leftVals[$fieldName] ?? NULL;
      $leftIsEmpty = in_array($leftVal, [NULL, '', []], TRUE);
      $rightIsEmpty = in_array($rightVal, [NULL, '', []], TRUE);

      if ($leftIsEmpty && !$rightIsEmpty) {
        $rightOnly[] = $fieldName;
        continue;
      }
      if ($rightIsEmpty && !$leftIsEmpty) {
        $leftOnly[] = $fieldName;
        continue;
      }
      if ($leftIsEmpty && $rightIsEmpty) {
        continue;
      }
      if ($leftVal !== $rightVal) {
        $different[] = $fieldName;
      }
    }

    return new DiffResult($leftVals, $rightVals, $different, $leftOnly, $rightOnly);
  }

  private function initializeFields($idValue = NULL): void {
    foreach (static::getFieldMetadata() as $name => $metadata) {
      $options = array_merge($metadata, ['bundle' => $this]);
      $this->$name = new Field($name, $options);
    }

    if ($idValue) {
      $this->id->load($idValue);
    }

    $this->_isInitialized = TRUE;
  }

  public function isLoaded(): bool {
    return $this->isLoaded;
  }

  public function isAltered(): bool {
    if (!$this->isTouched) {
      return FALSE;
    }
    if ($this->getId()) {
      $this->loadOnce();
    }
    foreach ($this->getFieldMetadata() as $fieldName => $x) {
      if ($this->$fieldName->isAltered()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  public function isTouched(): bool {
    return $this->isTouched;
  }

  public function getAllAsLoaded(): array {
    $this->loadOnce();
    $return = [];
    foreach ($this->getFieldMetadata() as $fieldName => $x) {
      $return[$fieldName] = $this->$fieldName->getAsLoaded();
    }
    return $return;
  }

  public function getAll(): array {
    $this->loadOnce();
    return $this->getAllWithoutLoading();
  }

  public function getAllWithoutLoading(): array {
    $return = [];
    foreach ($this->getFieldMetadata() as $fieldName => $x) {
      $return[$fieldName] = $this->$fieldName->get();
    }
    return $return;
  }

  public function getOrLoadCached(): static {
    if ($this->isLoaded()) {
      return $this->cache();
    }
    return static::getOrCreateCached(id: $this->getId());
  }

  protected function getWhereClauseForLoad(): array {
    return [['id', '=', $this->getId()]];
  }

  public function load(): self {
    $this->isLoaded = FALSE;

    if (!($id = $this->getId())) {
      throw new InvalidArgumentException('%s::%s requires the %s to have an id',
        static::class, __FUNCTION__, static::class);
    }

    $this->initializeFields($id);
    $this->isTouched = FALSE;

    $selects = static::getSelects();

    /** @var \Civi\Api4\Generic\AbstractGetAction $getAction */
    $getAction = $this->makeApi4Action('get');
    $result = $getAction
      ->setJoin($this->getJoins())
      ->setOrderBy($this->getOrderBys())
      ->setSelect(array_keys($selects))
      ->setWhere($this->getWhereClauseForLoad())
      ->execute();

    if (!$result->count()) {
      throw new InvalidArgumentException('Unable to retrieve '
        . static::getCiviEntityName() . ' id %d', $id);
    }

    $result = $result->last();

    foreach ($result as $key => $val) {
      /** @var \Civi\Osdi\LocalObject\Field $field */
      $field = $this->{$selects[$key]};
      $field->load($val);
    }

    $this->isLoaded = TRUE;
    return $this;
  }

  public function loadFromArray(array $array): self {
    $this->initializeFields();

    $selects = static::getSelects();

    foreach ($array as $key => $val) {
      if (array_key_exists($key, $this->getFieldMetadata())) {
        $fieldCamelName = $key;
      }
      elseif (array_key_exists($key, $selects)) {
        $fieldCamelName = $selects[$key];
      }
      else {
        continue;
      }

      $this->{$fieldCamelName}->load($val);
    }

    $this->isTouched = FALSE;
    $this->isLoaded = TRUE;
    return $this;
  }

  public function loadFromObject(LocalObjectInterface $otherObject): self {
    $this->initializeFields();
    foreach ($this->getFieldMetadata() as $fieldName => $x) {
      $this->{$fieldName}->load($otherObject->{$fieldName}->get());
    }
    $this->isTouched = FALSE;
    $this->isLoaded = TRUE;
    return $this;
  }

  public static function getOrCreateCached(
    ?int $id = NULL/*,
    ?LocalObjectInterface $objectToCache = NULL*/
  ): static {
    if (empty($id)) {
      throw new InvalidArgumentException('id must be provided');
    }

    $cache =& \Civi::$statics['osdiClient.local.objectCache'][static::class];
    $cacheItem =& $cache['id'][$id];

    if (empty($cacheItem)) {
      $cacheItem = new static($id);
    }
    return $cacheItem->loadOnce();
  }

  public function cache(): static {
    $cache =& \Civi::$statics['osdiClient.local.objectCache'][static::class];
    $cache['id'][$this->getId()] = $this;
    return $this;
  }

  public function loadOnce(): self {
    if (!$this->isLoaded()) {
      return $this->load();
    }
    return $this;
  }

  private function makeApi4Action(string $action, bool $checkPermissions = FALSE): AbstractAction {
    $api4Class = '\\Civi\\Api4\\' . static::getCiviEntityName();
    return call_user_func([$api4Class, $action], $checkPermissions);
  }

  abstract public function persist(): \Civi\Osdi\CrudObjectInterface;

  public function save(): \Civi\Osdi\CrudObjectInterface {
    \Civi::$statics['osdiClient.inProgress']['save'][] = $this;
    $key = array_key_last(\Civi::$statics['osdiClient.inProgress']['save']);
    try {
      return $this->persist();
    }
    finally {
      unset(\Civi::$statics['osdiClient.inProgress']['save'][$key]);
    }
  }

  public function touch() {
    $this->isTouched = TRUE;
  }

  public function trySave(): SaveResult {
    $result = new SaveResult();
    try {
      if (empty($this->getId())) {
        $statusMessage = 'created new record';
        $context = NULL;
      }
      else {
        $statusMessage = 'updated existing record';
        $changesBeingSaved = $this->diffChanges()->toArray();
        $context = ['changes' => $changesBeingSaved];
      }
      $this->save();
      $result->setStatusCode(SaveResult::SUCCESS);
      $result->setMessage($statusMessage);
      $result->setContext($context);
      $result->setReturnedObject($this);
    }
    catch (\CRM_Core_Exception $exception) {
      $result->setStatusCode(SaveResult::ERROR);
      $result->setMessage('exception when saving local record');
      $result->setContext(['exception' => $exception]);
    }
    return $result;
  }

  /**
   * @param int $value
   */
  public function setId($value) {
    $this->id->set($value);
  }

  public function getId(): ?int {
    return $this->id->get();
  }

}
