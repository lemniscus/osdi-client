<?php

namespace Civi\Osdi\ActionNetwork\Object;

use Civi\Osdi\RemoteObjectInterface;

class DiffResult {

  private array $differentFields;

  private array $leftOnlyFields;

  private array $rightOnlyFields;

  private RemoteObjectInterface $left;

  private RemoteObjectInterface $right;

  public function __construct(
    RemoteObjectInterface $left,
    RemoteObjectInterface $right,
    array $differentFields,
    array $leftOnlyFields,
    array $rightOnlyFields
  ) {
    $this->differentFields = $differentFields;
    $this->leftOnlyFields = $leftOnlyFields;
    $this->rightOnlyFields = $rightOnlyFields;
    $this->left = $left;
    $this->right = $right;
  }

  /**
   * @return array
   */
  public function getDifferentFields(): array {
    return $this->differentFields;
  }

  /**
   * @return array
   */
  public function getLeftOnlyFields(): array {
    return $this->leftOnlyFields;
  }

  /**
   * @return array
   */
  public function getRightOnlyFields(): array {
    return $this->rightOnlyFields;
  }

  public function getTotalDifferenceCount(): int {
    return count($this->differentFields)
      + count($this->leftOnlyFields)
      + count($this->rightOnlyFields);
  }

  public function toArray(): array {
    $return = [];
    $fieldNames = $this->getDifferentFields() + $this->getLeftOnlyFields()
      + $this->getRightOnlyFields();
    foreach ($fieldNames as $fieldName) {
      $return[$fieldName] = [
        $this->left->$fieldName->get(),
        $this->right->$fieldName->get(),
      ];
    }
    return $return;
  }

}
