<?php

namespace Civi\Osdi\ActionNetwork\Object;

class DiffResult {

  private array $differentFields;

  private array $leftOnlyFields;

  private array $rightOnlyFields;

  public function __construct(
    array $differentFields,
    array $leftOnlyFields,
    array $rightOnlyFields
  ) {
    $this->differentFields = $differentFields;
    $this->leftOnlyFields = $leftOnlyFields;
    $this->rightOnlyFields = $rightOnlyFields;
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

}
