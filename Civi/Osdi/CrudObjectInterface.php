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

  /**
   * @return static An already-loaded object from the cache matching this
   * object's id, or $this if $this is loaded
   */
  public function getOrLoadCached(): static;

  /**
   * Save to the short-term cache. This object will replace any with the same id
   * or URL.
   */
  public function cache();

  public function save(): self;

  public function trySave(): SaveResult;

  /**
   * Return a loaded object, from cache if possible, adding to the cache if
   * necessary. The returned object will match the criteria specified in the
   * arguments, but it will NOT necessarily be the same object every time.
   */
  public static function getOrCreateCached(): static;

}
