<?php


namespace Civi\Osdi\Generic;

use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Jsor\HalClient\HalResource;

class OsdiObject implements RemoteObjectInterface {

  /**
   * @var string
   */
  protected $namespace;

  /**
   * @var string
   */
  protected $id;

  /**
   * @var string
   */
  protected $type;

  /**
   * @var HalResource|null
   */
  protected $resource;

  /**
   * @var mixed[]
   */
  protected $alteredData = [];

  /**
   * @var string[]
   */
  protected $fieldsToClear = [];

  /**
   * OsdiObject constructor.
   * @param string $type
   * @param HalResource|null $resource
   * @param array|null $initData
   */
  public function __construct(string $type, ?HalResource $resource, ?array $initData = []) {
    $this->resource = $resource;
    $this->type = $type;
    if ($id = $this->extractIdFromResource($resource)) $this->id = $id;
    $this->alteredData = $initData;
  }

  public function getNamespace(): string {
    return '';
  }

  public function getType(): string {
    return $this->type;
  }

  public function getId() {
    return $this->id;
  }

  public function getOwnUrl(RemoteSystemInterface $system): string {
    if ($this->resource) return $this->resource->getFirstLink('self')->getHref();
    if ($id = $this->getId()) return $system->constructUrlFor($this->getType(), $id);
  }

  /**
   * @param string|null $fieldName
   * @return mixed
   * @throws InvalidArgumentException
   */
  public function getOriginal(string $fieldName) {
    if (!$this->isValidField($fieldName))
      throw new InvalidArgumentException('Not a valid field name: "%s', $fieldName);
    if ($this->resource) return $this->resource->getProperty($fieldName);
    return null;
  }

  /**
   * @param string $key
   * @return mixed|null
   */
  public function getAltered(string $key) {
    return $this->alteredData[$key] ?? null;
  }

  public function getAllAltered(): array {
    return $this->alteredData;
  }

  public function getFieldsToClearBeforeWriting(): array {
    return $this->fieldsToClear;
  }

  /**
   * @param string $id
   * @throws \CRM_Core_Exception
   */
  public function setId(string $id) {
    if ('' !== (string)$this->id) {
      throw new \CRM_Core_Exception(sprintf(
          'Cannot change the id of the %s whose id is already set to "%s".',
          static::class, $this->id));
    }
    $this->id = $id;
  }

  /**
   * @param string $fieldName
   * @param mixed $val
   * @throws InvalidArgumentException
   */
  public function set(string $fieldName, $val) {
    $this->throwExceptionIfNotValidField($fieldName);
    if ($this->isMultipleValueField($fieldName)) {
      if (!$this->isClearableField($fieldName)) throw new InvalidArgumentException(
          sprintf('Cannot "set" value; use "appendTo" instead for field "%s"', $fieldName)
      );
      $this->fieldsToClear[] = $fieldName;
    }
    $this->alteredData[$fieldName] = $val;
  }

  /**
   * @param string $fieldName
   * @param mixed $val
   * @throws InvalidArgumentException
   */
  public function appendTo(string $fieldName, $val) {
    $this->throwExceptionIfNotValidField($fieldName);
    if (!$this->isMultipleValueField($fieldName)) throw new InvalidArgumentException('Cannot append value to single-value field: "%s"', $fieldName);
    $this->alteredData[$fieldName][] = $val;
  }

  /**
   * @param string $fieldName
   * @throws InvalidArgumentException
   */
  public function clearField(string $fieldName) {
    if (!$this->isClearableField($fieldName)) throw new InvalidArgumentException(
        sprintf('Cannot clear field "%s"', $fieldName)
    );
    $this->fieldsToClear[] = $fieldName;
  }

  public static function isValidField(string $name): bool {
    return TRUE;
  }

  public static function isMultipleValueField(string $name): bool {
    return TRUE;
  }

  public static function isClearableField(string $fieldName): bool {
    return static::isMultipleValueField($fieldName);
  }

  protected function extractIdFromResource(?HalResource $resource): ?string {
    if (!$resource) return null;
    return $resource->getProperty('id');
  }

  /**
   * @param string $fieldName
   * @throws InvalidArgumentException
   */
  protected function throwExceptionIfNotValidField(string $fieldName): void {
    if (!$this->isValidField($fieldName)) throw new InvalidArgumentException('Not a valid field name: "%s"', $fieldName);
  }

}