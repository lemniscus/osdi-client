<?php

namespace Civi\Osdi\LocalObject;

use Civi\Api4\Generic\AbstractAction;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\LocalObjectInterface;
use Civi\Osdi\Result\Save as SaveResult;

abstract class AbstractLocalObject implements LocalObjectInterface {

  public Field $id;

  protected bool $isTouched = FALSE;

  protected bool $isLoaded = FALSE;

  public function __construct(int $idValue = NULL) {
    $this->initializeFields($idValue);
  }

  public function __clone() {
    foreach ($this->getFieldMetadata() as $name => $metadata) {
      $this->$name = clone $this->$name;
      $this->$name->setBundle($this);
    }
  }

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

  private function initializeFields($idValue = NULL): void {
    foreach ($this->getFieldMetadata() as $name => $metadata) {
      $options = array_merge($metadata, ['bundle' => $this]);
      $this->$name = new Field($name, $options);
    }

    if ($idValue) {
      $this->id->load($idValue);
    }
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

    foreach ($this->getFieldMetadata() as $camelName => $fieldMetaData) {
      if (array_key_exists('select', $fieldMetaData)) {
        $selects[$fieldMetaData['select']] = $camelName;
      }
    }

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

    foreach ($this->getFieldMetadata() as $camelName => $fieldMetaData) {
      if (array_key_exists('select', $fieldMetaData)) {
        $selects[$fieldMetaData['select']] = $camelName;
      }
    }

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
      $statusMessage = empty($this->getId())
        ? 'created new record'
        : 'updated existing record';
      $this->save();
      $result->setStatusCode(SaveResult::SUCCESS);
      $result->setMessage($statusMessage);
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
