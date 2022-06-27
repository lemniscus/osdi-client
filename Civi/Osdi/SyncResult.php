<?php

namespace Civi\Osdi;

use Civi\Osdi\LocalObject\LocalObjectInterface;

class SyncResult {

  const SUCCESS = 'success';

  const ERROR = 'error';

  const NO_SYNC_NEEDED = 'no sync needed';

  protected ?\Civi\Osdi\RemoteObjectInterface $remoteObject;

  protected ?\Civi\Osdi\LocalObject\LocalObjectInterface $localObject;

  protected ?string $statusCode;

  protected ?string $message;

  protected ?PersonSyncState $state;

  protected $context;

  public function __construct
  (
    LocalObjectInterface $localObject = NULL,
    RemoteObjectInterface $remoteObject = NULL,
    $statusCode = NULL,
    $statusMessage = NULL,
    PersonSyncState $state = NULL,
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
    return in_array(
      $this->statusCode,
      [
        self::ERROR,
      ]
    );
  }

  public function getLocalObject() {
    return $this->localObject;
  }

  public function getRemoteObject(): ?RemoteObjectInterface {
    return $this->remoteObject;
  }

  public function getStatusCode(): ?string {
    return $this->statusCode;
  }

  public function getContext() {
    return $this->context;
  }

  public function getMessage() {
    return $this->message;
  }

  public function getState(): ?PersonSyncState {
    return $this->state;
  }

}
