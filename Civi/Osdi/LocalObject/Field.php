<?php

namespace Civi\Osdi\LocalObject;

use Civi\Osdi\Exception\InvalidOperationException;

class Field {

  private bool $isLoaded = FALSE;

  private bool $isReadOnly = FALSE;

  private bool $isTouched = FALSE;

  private ?LocalObjectInterface $bundle;

  private ?string $loadedValue = NULL;

  private string $name;

  private ?string $newValue = NULL;

  private ?string $afterSet = NULL;

  public function __construct($name, array $options = []) {
    $this->name = $name;
    $this->afterSet = $options['afterSet'] ?? NULL;
    $this->bundle = $options['bundle'] ?? NULL;
    $this->isReadOnly = $options['readOnly'] ?? $this->isReadOnly;
  }

  public function load(?string $value) {
    $this->loadedValue = $value;
    $this->isLoaded = TRUE;
  }

  public function set(?string $value) {
    if ($this->isReadOnly) {
      throw new InvalidOperationException('Field "%s" is not settable',
        $this->name);
    }
    $this->newValue = $value;
    $this->isTouched = TRUE;
    if (isset($this->bundle)) {
      $this->bundle->touch();
      if (isset($this->afterSet)) {
        $this->bundle->{$this->afterSet}($this);
      }
    }
  }

  public function get(): ?string {
    if ($this->isTouched) {
      return $this->newValue;
    }
    return $this->loadedValue;
  }

  public function getLoaded(): ?string {
    return $this->loadedValue;
  }

  public function isLoaded(): bool {
    return $this->isLoaded;
  }

  public function isTouched(): bool {
    return $this->isTouched;
  }

  public function isAltered(): bool {
    return $this->isTouched && $this->newValue !== $this->loadedValue;
  }

  public function isAlteredLoose(): bool {
    return $this->isTouched && $this->newValue != $this->loadedValue;
  }

}
