<?php

namespace Civi\Osdi\LocalObject;

use Civi\Osdi\Exception\InvalidOperationException;

class Field {

  private bool $isLoaded = FALSE;

  private bool $isReadOnly = FALSE;

  private bool $isTouched = FALSE;

  private ?LocalObjectInterface $bundle;

  private string $name;

  /**
   * @var string|array|null
   */
  private $loadedValue = NULL;

  /**
   * @var string|array|null
   */
  private $newValue = NULL;

  private ?string $afterSet = NULL;

  public function __construct($name, array $options = []) {
    $this->name = $name;
    $this->afterSet = $options['afterSet'] ?? NULL;
    $this->bundle = $options['bundle'] ?? NULL;
    $this->isReadOnly = $options['readOnly'] ?? $this->isReadOnly;
  }

  /**
   * @param string|array|null $value
   *
   * @return void
   */
  public function load($value) {
    $this->loadedValue = $value;
    $this->isLoaded = TRUE;
  }

  /**
   * @param string|array|null $value
   *
   * @return void
   */
  public function set($value) {
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

  /**
   * @return string|array|null
   */
  public function get() {
    if ($this->isTouched) {
      return $this->newValue;
    }
    return $this->loadedValue;
  }

  /**
   * @return string|array|null
   */
  public function getAsLoaded(): ?string {
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
