<?php

namespace Civi\Osdi\LocalObject;

use Civi\Api4\Generic\AbstractAction;
use Civi\Osdi\Exception\InvalidArgumentException;

abstract class Base implements LocalObjectInterface {

  public const CIVI_ENTITY = '';

  public const FIELDS = [];

  public const JOINS = [];

  public const ORDER_BY = [];

  public Field $id;

  protected bool $isTouched = FALSE;

  protected bool $isLoaded = FALSE;

  public function __construct(int $idValue = NULL) {
    foreach (static::FIELDS as $name => $metadata) {
      $options = array_merge($metadata, ['bundle' => $this]);
      $this->$name = new Field($name, $options);
    }
    if ($idValue) {
      $this->id->load($idValue);
    }
  }

  public function __clone() {
    foreach (static::FIELDS as $name => $metadata) {
      $this->$name = clone $this->$name;
    }
  }

  protected function getWhereClauseForLoad(): array {
    return [['id', '=', $this->getId()]];
  }

  public function getId(): ?int {
    return $this->id->get();
  }

  public function loadOnce(): self {
    if (!$this->isLoaded()) {
      return $this->load();
    }
    return $this;
  }

  public function isLoaded(): bool {
    return $this->isLoaded;
  }

  public function setId(int $value) {
    $this->id->set($value);
  }

  public function isAltered(): bool {
    if (!$this->isTouched) {
      return FALSE;
    }
    if ($this->getId()) {
      $this->loadOnce();
    }
    foreach (static::FIELDS as $fieldName => $x) {
      if ($this->$fieldName->isAltered()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  public function isTouched(): bool {
    return $this->isTouched;
  }

  public function load(): self {
    $this->isLoaded = FALSE;

    if (!($id = $this->getId())) {
      throw new InvalidArgumentException('%s::%s requires the %s to have an id',
        static::class, __FUNCTION__, static::class);
    }

    foreach (static::FIELDS as $camelName => $fieldMetaData) {
      if (array_key_exists('select', $fieldMetaData)) {
        $selects[$fieldMetaData['select']] = $camelName;
      }
    }

    /** @var \Civi\Api4\Generic\AbstractGetAction $getAction */
    $getAction = $this->makeApi4Action('get');
    $result = $getAction
      ->setJoin(static::JOINS)
      ->setOrderBy(static::ORDER_BY)
      ->setSelect(array_keys($selects))
      ->setWhere($this->getWhereClauseForLoad())
      ->execute();

    if (!$result->count()) {
      throw new InvalidArgumentException('Unable to retrieve '
        . static::CIVI_ENTITY . ' id %d', $id);
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

  public function getAllLoaded(): array {
    $this->loadOnce();
    $return = [];
    foreach (static::FIELDS as $fieldName => $x) {
      $return[$fieldName] = $this->$fieldName->getLoaded();
    }
    return $return;
  }

  public function getAll(): array {
    $this->loadOnce();
    return $this->getAllWithoutLoading();
  }

  public function touch() {
    $this->isTouched = TRUE;
  }

  public function delete(): ?\Civi\Api4\Generic\Result {
    if (empty($id = $this->getId())) {
      return NULL;
    }

    /** @var \Civi\Api4\Generic\DAODeleteAction $deleteAction */
    $deleteAction = $this->makeApi4Action('delete');
    return $deleteAction
      ->addWhere('id', '=', $id)
      ->execute();
  }

  public function getAllWithoutLoading(): array {
    $return = [];
    foreach (static::FIELDS as $fieldName => $x) {
      $return[$fieldName] = $this->$fieldName->get();
    }
    return $return;
  }

  private function makeApi4Action(string $action, bool $checkPermissions = FALSE): AbstractAction {
    $api4Class = '\\Civi\\Api4\\' . static::CIVI_ENTITY;
    return call_user_func([$api4Class, $action], $checkPermissions);
  }

}