<?php

namespace Civi\Osdi\ActionNetwork\Object;

class DiffResult {

  private array $differentFields;

  private array $leftOnlyFields;

  private array $rightOnlyFields;

  private array $left;

  private array $right;

  public function __construct(
    array $left,
    array $right,
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
        'L' => $this->left[$fieldName],
        'R' => $this->right[$fieldName],
      ];
    }
    return $return;
  }

}
