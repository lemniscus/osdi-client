<?php

namespace Civi\Osdi;

class SaveResult {

  const SUCCESS = 'success';

  const ERROR = 'error';

  protected ?RemoteObjectInterface $savedObject;

  protected ?string $statusCode;

  protected ?string $message;

  /**
   * @var mixed
   */
  protected $context;

  public function __construct(RemoteObjectInterface $savedObject = NULL,
                              $statusCode = NULL,
                              $statusMessage = NULL,
                              $context = NULL) {
    $this->savedObject = $savedObject;
    $this->statusCode = $statusCode;
    $this->message = $statusMessage;
    $this->context = $context;
  }

  public function getReturnedObject(): ?RemoteObjectInterface {
    return $this->savedObject;
  }

  public function isError(): bool {
    return in_array(
      $this->statusCode,
      [
        self::ERROR,
      ]
    );
  }

  public function getStatus(): ?string {
    return $this->statusCode;
  }

  public function getContext() {
    return $this->context;
  }

  /**
   * @return string
   */
  public function getMessage() {
    return $this->message;
  }

}