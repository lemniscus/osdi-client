<?php

namespace Civi\Osdi\Result;

use Civi\Osdi\LocalObjectInterface;
use Civi\Osdi\RemoteObjectInterface;

class Sync extends AbstractResult implements \Civi\Osdi\ResultInterface {

  const SUCCESS = 'success';

  const ERROR = 'error';

  const NO_SYNC_NEEDED = 'no sync needed';

  protected ?RemoteObjectInterface $remoteObject;

  protected ?LocalObjectInterface $localObject;

  /**
   * @var mixed
   */
  protected $state;

  public function __construct
  (
    LocalObjectInterface $localObject = NULL,
    RemoteObjectInterface $remoteObject = NULL,
    $statusCode = NULL,
    $statusMessage = NULL,
    $state = NULL,
    $context = NULL
  ) {
    $this->localObject = $localObject;
    $this->remoteObject = $remoteObject;
    $this->statusCode = $statusCode;
    $this->message = $statusMessage;
    $this->state = $state;
    $this->context = $context;
  }

  public function isError(): bool {
    return $this->statusCode === self::ERROR;
  }

  public function getLocalObject(): ?LocalObjectInterface {
    return $this->localObject;
  }

  public function setLocalObject(?LocalObjectInterface $localObject): Sync {
    $this->localObject = $localObject;
    return $this;
  }

  public function getRemoteObject(): ?RemoteObjectInterface {
    return $this->remoteObject;
  }

  public function setRemoteObject(?RemoteObjectInterface $remoteObject): Sync {
    $this->remoteObject = $remoteObject;
    return $this;
  }

  /**
   * @return mixed|null
   */
  public function getState() {
    return $this->state;
  }

  /**
   * @param mixed $state
   *
   * @return $this
   */
  public function setState($state): self {
    $this->state = $state;
    return $this;
  }

  public function toArray(): array {
    $localObject = $this->getLocalObject();
    $remoteObject = $this->getRemoteObject();
    $state = $this->getState();
    $stateArray = $state && method_exists($state, 'toArray')
      ? $state->toArray()
      : $state;
    ;return [
      'type' => $this->getType(),
      'status' => $this->getStatusCode(),
      'message' => $this->getMessage(),
      'localObject' => $localObject ? $localObject->getAll() : NULL,
      'remoteObject' => $remoteObject ? $remoteObject->getAll() : NULL,
      'sync state' => $stateArray,
      'context' => $this->getContextAsArray(),
    ];
  }

}
