<?php

namespace Civi\Osdi\ActionNetwork\Object;

use Civi\Osdi\Exception\InvalidOperationException;
use Civi\Osdi\RemoteObjectInterface;

class Field {

  private ?string $afterSet = NULL;

  private ?RemoteObjectInterface $bundle;

  private bool $createOnly = FALSE;

  private bool $isLoaded = FALSE;

  private bool $isTouched = FALSE;

  private bool $mungeNulls = FALSE;

  private string $name;

  private bool $readOnly = FALSE;

  /**
   * @var string|array|null
   */
  private $newValue = NULL;

  private array $path;

  public function __construct($name, RemoteObjectInterface $bundle, array $options = []) {
    $this->name = $name;
    $this->bundle = $bundle;
    $this->path = $options['path'];
    foreach (['afterSet', 'createOnly', 'readOnly', 'mungeNulls'] as $property) {
      $this->$property = $options[$property] ?? $this->$property;
    }
  }

  /**
   * @param string|array|null $value
   *
   * @return void
   */
  public function set($value) {
    if ($this->readOnly) {
      throw new InvalidOperationException('Field "%s" is not settable',
        $this->name);
    }
    if ($this->createOnly) {
      if ($this->bundle->getId()) {
        throw new InvalidOperationException('Field "%s" is not settable'
          . ' on a %s which has an id', $this->name, get_class($this->bundle));
      }
    }
    $this->newValue = $value;
    $this->isTouched = TRUE;
    $this->bundle->touch();
    if (isset($this->afterSet)) {
      $this->bundle->{$this->afterSet}($this);
    }
  }

  /**
   * @return string|array|null
   */
  public function get() {
    if ($this->isTouched) {
      return $this->newValue;
    }
    return $this->restoreNull($this->getAsLoaded());
  }

  /**
   * @return string|array|null
   */
  public function getAsLoaded() {
    if (!$resource = $this->bundle->getResource()) {
      return NULL;
    }

    if ('_links' === $this->path[0]) {
      $links = $resource->getLink($this->path[1]);
      $linkOffset = $this->path[2] ?? 0;
      return $links[$linkOffset]->getHref();
    }

    $val = $resource->getProperty($this->path[0]) ?? NULL;
    for ($i = 1; $i < count($this->path); $i++) {
      $val = $val[$this->path[$i]] ?? NULL;
    }
    return $val;
  }

  /**
   * @return string|array|null
   */
  public function getWithNullPreparedForUpdate() {
    if (!$this->isTouched) {
      return $this->getAsLoaded();
    }
    if (!$this->mungeNulls) {
      return $this->get();
    }
    if (is_null($this->newValue) && !is_null($this->getAsLoaded())) {
      return $this->bundle::NULL_CHAR;
    }
    return $this->newValue;
  }

  public function isLoaded(): bool {
    return $this->isLoaded;
  }

  public function isTouched(): bool {
    return $this->isTouched;
  }

  public function isAltered(): bool {
    return $this->isTouched && $this->newValue !== $this->getAsLoaded();
  }

  public function isAlteredLoose(): bool {
    return $this->isTouched && $this->newValue != $this->getAsLoaded();
  }

  private function restoreNull($val) {
    if ($this->mungeNulls && $val === $this->bundle::NULL_CHAR) {
      return NULL;
    }
    return $val;
  }

  public function setBundle(RemoteObjectInterface $bundle) {
    $this->bundle = $bundle;
  }

}
