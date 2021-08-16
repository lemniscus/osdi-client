<?php

namespace Civi\Osdi;

class SaveResult {

  const SUCCESS = 'success';

  const ERROR = 'error';

  /**
   * @var \Civi\Osdi\RemoteObjectInterface
   */
  protected $savedObject;

  /**
   * @var string
   */
  protected $statusCode;

  /**
   * @var string
   */
  protected $message;

  /**
   * @var mixed
   */
  protected $context;

  public function __construct(RemoteObjectInterface $savedObject = NULL, $statusCode = NULL, $statusMessage = NULL, $context = NULL) {
    $this->savedObject = $savedObject;
    $this->statusCode = $statusCode;
    $this->message = $statusMessage;
    $this->context = $context;
  }

  public function object(): ?RemoteObjectInterface {
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

  public function status(): ?string {
    return $this->statusCode;
  }

  public function context() {
    return $this->context;
  }

}