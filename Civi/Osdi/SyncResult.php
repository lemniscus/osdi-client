<?php

namespace Civi\Osdi;

class SyncResult {

  const SUCCESS = 'success';

  const ERROR = 'error';

  protected ?\Civi\Osdi\RemoteObjectInterface $remoteObject;

  protected $localObject;

  protected ?string $statusCode;

  protected ?string $message;

  protected $context;

  public function __construct($localObject = NULL,
                              RemoteObjectInterface $remoteObject = NULL,
                              $statusCode = NULL,
                              $statusMessage = NULL,
                              $context = NULL) {
    $this->localObject = $localObject;
    $this->remoteObject = $remoteObject;
    $this->statusCode = $statusCode;
    $this->message = $statusMessage;
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

  public function getStatus(): ?string {
    return $this->statusCode;
  }

  public function getContext() {
    return $this->context;
  }

  public function getMessage() {
    return $this->message;
  }

}