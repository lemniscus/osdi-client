<?php

namespace Civi\Osdi\ActionNetwork\Object;

use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\Exception\InvalidOperationException;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Jsor\HalClient\HalResource;

abstract class Base implements RemoteObjectInterface {

  public const NULL_CHAR = "\xE2\x90\x80";

  protected ?string $_id = NULL;

  protected bool $_isTouched = FALSE;

  protected ?HalResource $_resource = NULL;

  protected RemoteSystemInterface $_system;

  public function __construct(RemoteSystemInterface $system,
                              ?HalResource $resource = NULL) {
    $this->_system = $system;
    if ($resource) {
      $this->load($resource);
    }

    foreach (static::FIELDS as $name => $metadata) {
      $this->$name = new Field($name, $this, $metadata);
    }
  }

  public function __clone() {
    foreach (static::FIELDS as $name => $metadata) {
      $this->$name = clone $this->$name;
    }
  }

  public function isAltered(): bool {
    if (!$this->_isTouched) {
      return FALSE;
    }
    $this->loadOnce();
    foreach (static::FIELDS as $fieldName => $x) {
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
    foreach (static::FIELDS as $fieldName => $metadata) {
      $val = $this->$fieldName->get();
      $path = $metadata['path'];
      \CRM_Utils_Array::pathSet($allValues, $path, $val);
    }
    return $allValues;
  }

  public function getAllOriginal(): array {
    if (!$this->isLoaded()) {
      return [];
    }

    $allValues = [];
    foreach (static::FIELDS as $fieldName => $metadata) {
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
    foreach (static::FIELDS as $fieldName => $metadata) {
      $val = $this->$fieldName->getWithNullPreparedForUpdate();
      $path = $metadata['path'];
      \CRM_Utils_Array::pathSet($allValues, $path, $val);
    }
    return $allValues;
  }

  public function delete() {
    throw new InvalidOperationException('Objects of type %s cannot be '
      . 'deleted via the Action Network API', $this->getType());
  }

  public function setId(string $val) {
    $this->_id = $val;
  }

  public function getId(): ?string {
    return $this->_id;
  }

  public function load(HalResource $resource = NULL): self {
    if (is_null($resource)) {
      $resource = $this->_system->fetch($this);
    }
    $this->_resource = $resource;

    if ($id = $this->extractIdFromResource($resource)) {
      $this->_id = $id;
    }

    return $this;
  }

  public function loadFromArray(array $flatFields): self {
    $nestedArray = [];
    foreach ($flatFields as $flatFieldName => $value) {
      $path = static::FIELDS[$flatFieldName]['path'];
      \CRM_Utils_Array::pathSet($nestedArray, $path, $value);
    }
    $resource = HalResource::fromArray($this->_system->getClient(), $nestedArray);
    return $this->load($resource);
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
    return $this;
  }

  public function touch() {
    $this->_isTouched = TRUE;
  }

  public function isTouched(): bool {
    return $this->_isTouched;
  }

  public function getUrlForRead(): ?string {
    try {
      if ($selfLink = $this->_resource->getFirstLink('self')) {
        return $selfLink->getHref();
      }
    }
    catch (\Throwable $e) {
      try {
        return $this->constructOwnUrl();
      }
      catch (\Throwable $e) {
        throw new EmptyResultException(
          'Could not find or create url for "%s" with type "%s" and id "%s"',
          __CLASS__, $this->getType(), $this->getId());
      }
    }
    return NULL;
  }

  public function getUrlForUpdate(): string {
    return $this->getUrlForRead();
  }

  public function getUrlForDelete(): string {
    return $this->getUrlForRead();
  }

  public function isSupersetOf(RemoteObjectInterface $otherObject,
                               bool $emptyValuesAreOk = FALSE,
                               bool $ignoreModifiedDate = FALSE): bool {
    $recursiveCompare =
      function ($smallSet, $bigSet)
           use (&$recursiveCompare, $emptyValuesAreOk, $ignoreModifiedDate) {
        if (!is_array($smallSet) && !is_array($bigSet)) {
          if ($emptyValuesAreOk && empty($smallSet)) {
            return TRUE;
          }
          return $smallSet === $bigSet;
        }
        if (!is_array($smallSet) || !is_array($bigSet)) {
          return FALSE;
        }
        foreach ($smallSet as $key => $value) {
          if ($ignoreModifiedDate && 'modified_date' === $key) {
            continue;
          }
          if (!$recursiveCompare($smallSet[$key], $bigSet[$key] ?? NULL)) {
            return FALSE;
          }
        }
        return TRUE;
      };

    return $recursiveCompare($otherObject->getAll(), $this->getAll());
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
        \Civi::log()
          ->debug('Identifiers array was empty; got id from self link', [$selfUrl]);
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

}
