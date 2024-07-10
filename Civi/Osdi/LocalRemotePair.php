<?php

namespace Civi\Osdi;

use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\Exception\InvalidOperationException;
use Civi\Osdi\Result\MatchResult;
use Civi\Osdi\Result\ResultStack;
use Civi\Osdi\Result\Sync;

class LocalRemotePair {

  const ORIGIN_LOCAL = 'local';
  const ORIGIN_REMOTE = 'remote';

  private ?LocalObjectInterface $localObject;
  private ?RemoteObjectInterface $remoteObject;
  private ?string $localClass = NULL;
  private ?string $remoteClass = NULL;
  private ResultStack $resultStack;
  private ?string $origin = NULL;
  private array $vars = [];

  public function __construct(
      LocalObjectInterface $localObject = NULL,
    RemoteObjectInterface $remoteObject = NULL
  ) {
    $this->localObject = $localObject;
    $this->remoteObject = $remoteObject;
    $this->resultStack = new ResultStack();
  }

  public function __clone() {
    foreach (get_object_vars($this) as $propertyName => $propertyValue) {
      if (is_object($propertyValue)) {
        $this->$propertyName = clone $propertyValue;
      }
    }
  }

  public function __debugInfo(): ?array {
    return $this->__serialize();
  }

  public function getLocalObject(): ?LocalObjectInterface {
    return $this->localObject;
  }

  public function setLocalObject(?LocalObjectInterface $localObject): self {
    $this->localObject = $localObject;
    return $this;
  }

  public function getRemoteObject(): ?RemoteObjectInterface {
    return $this->remoteObject;
  }

  public function setRemoteObject(?RemoteObjectInterface $remoteObject): self {
    $this->remoteObject = $remoteObject;
    return $this;
  }

  public function getOrigin(bool $throwExceptionIfNotSet = FALSE): ?string {
    if ($throwExceptionIfNotSet && empty($this->origin)) {
      throw new InvalidOperationException('Origin retrieved without being set first');
    }
    return $this->origin;
  }

  public function isOriginLocal(): bool {
    return self::ORIGIN_LOCAL === $this->getOrigin(TRUE);
  }

  public function isOriginRemote(): bool {
    return self::ORIGIN_REMOTE === $this->getOrigin(TRUE);
  }

  /**
   * Fluent setter for sync origin.
   *
   * @param string $origin Sync direction of the pair: either
   *   LocalRemotePair::ORIGIN_LOCAL or LocalRemotePair::ORIGIN_REMOTE
   *
   * @throws \Civi\Osdi\Exception\InvalidArgumentException
   */
  public function setOrigin(string $origin): self {
    if (!in_array($origin, [self::ORIGIN_LOCAL, self::ORIGIN_REMOTE])) {
      throw new InvalidArgumentException('Invalid origin code: "%s"', $origin);
    }
    $this->origin = $origin;
    return $this;
  }

  public function getOriginObject() {
    if (empty($this->origin)) {
      throw new InvalidArgumentException('No origin code provided');
    }
    if (self::ORIGIN_LOCAL === $this->origin) {
      return $this->getLocalObject();
    }
    return $this->getRemoteObject();
  }

  public function getTargetObject() {
    if (empty($this->origin)) {
      throw new InvalidArgumentException('No origin code provided');
    }
    if (self::ORIGIN_REMOTE === $this->origin) {
      return $this->getLocalObject();
    }
    return $this->getRemoteObject();
  }

  /**
   * @param \Civi\Osdi\LocalObjectInterface|\Civi\Osdi\RemoteObjectInterface $object
   *
   * @return $this
   */
  public function setOriginObject($object): self {
    if ($this->isOriginLocal()) {
      return $this->setLocalObject($object);
    }
    return $this->setRemoteObject($object);
  }

  public function setTargetObject(CrudObjectInterface $object): self {
    if ($this->isOriginLocal()) {
      return $this->setRemoteObject($object);
    }
    return $this->setLocalObject($object);
  }

  public function getLastResult(): ?ResultInterface {
    foreach ($this->getResultStack() as $lastResult) {
      return $lastResult;
    }
    return NULL;
  }

  public function getLastResultOfType(string $type) {
    return $this->getResultStack()->getLastOfType($type);
  }

  public function getResultStack(): ResultStack {
    return $this->resultStack;
  }

  public function pushResult(ResultInterface $result): self {
    $this->resultStack->push($result);
    return $this;
  }

  public function isError(): bool {
    $lastResult = $this->getLastResult();
    return $lastResult ? $lastResult->isError() : FALSE;
  }

  /**
   * @deprecated
   * @todo remove
   */
  public function getLocalClass(): ?string {
    return $this->localClass;
  }

  public function setLocalClass(?string $localPersonClass): self {
    $this->localClass = $localPersonClass;
    return $this;
  }

  public function setRemoteClass(?string $className) {
    $this->remoteClass = $className;
    return $this;
  }

  public function __serialize(): array {
    return [
      'origin' => $this->origin,
      'localObject' => $this->localObject ?
        $this->localObject->getAllWithoutLoading() : NULL,
      'remoteObject' => $this->remoteObject ?
        $this->remoteObject->getArrayForCreate() : NULL,
      'resultStack' => $this->resultStack->toArray(),
    ];
  }

  public function getVar(string $key) {
    return $this->vars[$key] ?? NULL;
  }

  public function setVar(string $key, $value) {
    $this->vars[$key] = $value;
  }

}
