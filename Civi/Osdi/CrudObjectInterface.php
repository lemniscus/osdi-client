<?php

namespace Civi\Osdi;

use Civi\Osdi\Result\Save as SaveResult;

interface CrudObjectInterface {

  /**
   * @param string|int $val
   */
  public function setId($val);

  /**
   * @return string|int
   */
  public function getId();

  public function isAltered(): bool;

  public function isLoaded(): bool;

  public function isTouched(): bool;

  public function delete();

  public function load(): self;

  public function loadOnce(): self;

  public function save(): self;

  public function trySave(): SaveResult;

}