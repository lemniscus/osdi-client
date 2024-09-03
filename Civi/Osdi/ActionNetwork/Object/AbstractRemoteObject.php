<?php

namespace Civi\Osdi\ActionNetwork\Object;

use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\Exception\InvalidOperationException;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\Result\Diff as DiffResult;
use Civi\Osdi\Result\Save as SaveResult;
use Civi\OsdiClient;
use CRM_OSDI_ExtensionUtil as E;
use Jsor\HalClient\HalResource;

abstract class AbstractRemoteObject implements RemoteObjectInterface {

  public const NULL_CHAR = "\xE2\x90\x80";

  protected ?string $_id = NULL;

  protected bool $_isTouched = FALSE;

  protected ?HalResource $_resource = NULL;

  protected RemoteSystemInterface $_system;

  protected bool $_isInitialized = FALSE;

  public function __construct(RemoteSystemInterface $system,
                              ?HalResource $resource = NULL) {
    $this->_system = $system;
    $this->initializeFields();
    if ($resource) {
      $this->load($resource);
    }
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
      return $this->getArrayForCreate();
    }
    catch (\Throwable $e) {
      return get_object_vars($this);
      //return ['[could not render object for debugging]', __CLASS__, __FUNCTION__];
    }
  }

  public function isAltered(): bool {
    if (!$this->_isTouched) {
      return FALSE;
    }
    try {
      $this->loadOnce();
    }
    catch (EmptyResultException $e) {
    }
    foreach ($this->getFieldMetadata() as $fieldName => $x) {
      if ($this->$fieldName->isAltered()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  public function getAll(): array {
    $this->loadOnce();
    return $this->getAllWithoutLoading();
  }

  public function getAllWithoutLoading(): array {
    $allValues = [];
    foreach ($this->getFieldMetadata() as $fieldName => $metadata) {
      $val = $this->$fieldName->get();
      $path = $metadata['path'];
      \CRM_Utils_Array::pathSet($allValues, $path, $val);
    }
    return $allValues;
  }

  protected function getAllForCompare() {
    $allValues = [];
    foreach ($this->getFieldMetadata() as $fieldName => $metadata) {
      $allValues[$fieldName] = $this->getFieldValueForCompare($fieldName);
    }
    return $allValues;
  }

  public function getAllOriginal(): array {
    if (!$this->isLoaded()) {
      return [];
    }

    $allValues = [];
    foreach ($this->getFieldMetadata() as $fieldName => $metadata) {
      $val = $this->$fieldName->getOriginal();
      $path = $metadata['path'];
      \CRM_Utils_Array::pathSet($allValues, $path, $val);
    }
    return $allValues;
  }

  public function getArrayForCreate(): array {
    return $this->getAllWithoutLoading();
  }

  public function getArrayForUpdate(): array {
    $this->loadOnce();

    $allValues = [];
    foreach ($this->getFieldMetadata() as $fieldName => $metadata) {
      $val = $this->$fieldName->getWithNullPreparedForUpdate();
      $path = $metadata['path'];
      \CRM_Utils_Array::pathSet($allValues, $path, $val);
    }
    return $allValues;
  }

  public function delete() {
    throw new InvalidOperationException('Objects of type %s cannot be '
      . 'deleted via the Action Network API', static::getType());
  }

  /**
   * @param string $val
   */
  public function setId($val) {
    $this->_id = $val;
  }

  public function getId(): ?string {
    return $this->_id;
  }

  public function load(HalResource $resource = NULL): self {
    if (is_null($resource)) {
      if ($this->getUrlForRead()) {
        /** @var \Jsor\HalClient\HalResource $resource */
        $resource = $this->_system->fetch($this);
        if (!$resource->getProperties()) {
          throw new EmptyResultException('Action Network gave us nothing at %s',
            $this->getUrlForRead());
        }
      }
      else {
        return $this;
      }
    }
    $this->_resource = $resource;

    $id = $this->extractIdFromResource($resource);
    if ($id) {
      $this->_id = $id;
    }

    return $this;
  }

  public static function loadFromId(string $id, ?RemoteSystemInterface $system = NULL): self {
    $system = $system ??
      OsdiClient::container()->getSingle('RemoteSystem', 'ActionNetwork');
    $object = new static($system);
    $object->setId($id);
    return $object->load();
  }

  public function loadFromArray(array $flatFields): self {
    $nestedArray = [];
    foreach ($flatFields as $flatFieldName => $value) {
      $path = $this->getFieldMetadata()[$flatFieldName]['path'];
      \CRM_Utils_Array::pathSet($nestedArray, $path, $value);
    }
    $resource = HalResource::fromArray($this->_system->getClient(), $nestedArray);
    return $this->load($resource);
  }

  public function loadFromObject(RemoteObjectInterface $otherObject): RemoteObjectInterface {
    $this->initializeFields();
    return $this->load($otherObject->getResource());
  }

  public function loadOnce(): self {
    if (!$this->isLoaded()) {
      return $this->load();
    }
    return $this;
  }

  public function isLoaded(): bool {
    return !is_null($this->_resource);
  }

  public function getResource(): ?HalResource {
    return $this->_resource;
  }

  public function save(): self {
    $this->load($this->_system->save($this));
    $this->initializeFields();
    return $this;
  }

  public function trySave(): SaveResult {
    $statusCode = $statusMessage = $context = NULL;

    $objectBeforeSaving = clone $this;
    $changesBeingSaved = $this->diffChanges()->toArray();

    try {
      $this->save();
      $statusCode = SaveResult::SUCCESS;
      $context = ['changes' => $changesBeingSaved];
    }

    catch (\Throwable $e) {
      $statusCode = SaveResult::ERROR;
      $statusMessage = $e->getMessage();
      $context = [
        'object' => $objectBeforeSaving,
        'exception' => $e,
      ];
      return new SaveResult(NULL, $statusCode, $statusMessage, $context);
    }

    if ($this->saveWasImperfect($objectBeforeSaving)) {
      $statusCode = SaveResult::ERROR;
      $statusMessage = E::ts(
        'Some or all of the %1 object could not be saved.',
        [1 => $objectBeforeSaving->getType()],
      );
      $context = [
        'diff with left=sent, right=response' => self::diff($objectBeforeSaving, $this)->toArray(),
        'intended changes' => $changesBeingSaved,
        'sent' => $objectBeforeSaving->getArrayForCreate(),
        'response' => $this->getArrayForCreate(),
      ];
    }

    return new SaveResult($this, $statusCode, $statusMessage, $context);
  }

  public function touch() {
    $this->_isTouched = TRUE;
  }

  public function isTouched(): bool {
    return $this->_isTouched;
  }

  public function getUrlForRead(): ?string {
    if ($this->_resource) {
      try {
        $selfLink = $this->_resource->getFirstLink('self');
        if ($selfLink) {
          return $selfLink->getHref();
        }
      }
      catch (\Jsor\HalClient\Exception\InvalidArgumentException $e) {
      }
    }

    try {
      return $this->constructOwnUrl();
    }
    catch (EmptyResultException $e) {
      return NULL;
    }
  }

  public function getUrlForUpdate(): string {
    return $this->getUrlForRead();
  }

  public function getUrlForDelete(): string {
    return $this->getUrlForRead();
  }

  /**
   * Compare $left with $right and report which values, if any, are different.
   * The empty values NULL, '', and [] are all considered equivalent; comparison
   * of non-empty values is strict (type-sensitive). Before the comparison,
   * values may be normalized by getAllForCompare().
   */
  public static function diff(self $left, self $right): DiffResult {
    $different = $leftOnly = $rightOnly = [];

    $leftVals = $left->getAllForCompare();
    $rightVals = $right->getAllForCompare();

    foreach ($leftVals as $fieldName => $leftVal) {
      $rightVal = $rightVals[$fieldName] ?? NULL;
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

  public function diffChanges(): DiffResult {
    $originalThis = new static($this->_system, $this->_resource);
    return static::diff($originalThis, $this);
  }

  public function equals(self $comparee, array $ignoring = []): bool {
    foreach ($this->getFieldMetadata() as $fieldName => $metadata) {
      if ($this->$fieldName->get() !== $comparee->$fieldName->get()) {
        if (!in_array($fieldName, $ignoring)) {
          return FALSE;
        }
      }
    }
    return TRUE;
  }

  public function isSupersetOf($otherObject, array $ignoring): bool {
    if (get_class($this) !== get_class($otherObject)) {
      throw new InvalidArgumentException('Object passed to ' . __FUNCTION__
        . ' must be of the same class');
    }

    $diffResult = static::diff($this, $otherObject);
    if (array_diff($diffResult->getRightOnlyFields(), $ignoring)) {
      return FALSE;
    }

    $different = array_diff($diffResult->getDifferentFields(), $ignoring);
    foreach ($different as $fieldName) {
      if (!is_array($thisValue = $this->$fieldName->get())) {
        return FALSE;
      }
      if (!is_array($otherValue = $otherObject->$fieldName->get())) {
        return FALSE;
      }
      if (array_diff($thisValue, $otherValue)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  protected function constructOwnUrl(): string {
    if (empty($id = $this->getId())) {
      throw new EmptyResultException('Cannot calculate a url for an object that has no id');
    }
    return $this->getUrlForCreate() . "/$id";
  }

  protected function extractIdFromResource(?HalResource $resource): ?string {
    if (!$resource) {
      return NULL;
    }
    $identifiers = $this->_resource->getProperty('identifiers');
    if (!$identifiers) {
      $selfLink = $resource->hasLink('self') ? $resource->getFirstLink('self') : NULL;

      if ($selfLink) {
        $selfUrl = $selfLink->getHref();
        //\Civi::log()
        //  ->debug('Identifiers array was empty; got id from self link', [$selfUrl]);
        return substr($selfUrl, strrpos($selfUrl, '/') + 1);
      }
      return NULL;
    }
    $prefix = 'action_network:';
    $prefixLength = 15;
    foreach ($identifiers as $identifier) {
      if ($prefix === substr($identifier, 0, $prefixLength)) {
        return substr($identifier, $prefixLength);
      }
    }
    return NULL;
  }

  private function initializeFields(): void {
    foreach ($this->getFieldMetadata() as $name => $metadata) {
      $this->$name = new Field($name, $this, $metadata);
    }
    $this->_isTouched = FALSE;
    $this->_isInitialized = TRUE;
  }

  protected function getFieldValueForCompare(string $fieldName) {
    return $this->$fieldName->get();
  }

  protected function saveWasImperfect(AbstractRemoteObject $objectBeforeSaving): bool {
    return !$this->isSupersetOf(
      $objectBeforeSaving,
      ['identifiers', 'createdDate', 'modifiedDate']
    );
  }

}
