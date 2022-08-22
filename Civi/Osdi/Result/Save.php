<?php

namespace Civi\Osdi\Result;

use Civi\Osdi\LocalObject\LocalObjectInterface;
use Civi\Osdi\RemoteObjectInterface;

class Save extends AbstractResult implements \Civi\Osdi\ResultInterface{

  use SimpleErrorTrait;

  const ERROR = 'error';

  const SUCCESS = 'success';

  /**
   * @var RemoteObjectInterface|LocalObjectInterface|null
   */
  protected $returnedObject;

  protected ?string $statusCode;

  protected ?string $message;

  /**
   * @var mixed
   */
  protected $context;

  /**
   * @param RemoteObjectInterface|LocalObjectInterface|null $returnedObject
   * @param string $statusCode
   * @param string $statusMessage
   * @param mixed $context
   */
  public function __construct($returnedObject = NULL,
                              string $statusCode = NULL,
                              string $statusMessage = NULL,
                              $context = NULL) {
    $this->returnedObject = $returnedObject;
    $this->statusCode = $statusCode;
    $this->message = $statusMessage;
    $this->context = $context;
  }

  public function toArray(): array {
    $returnedObject = $this->returnedObject;
    return [
      'type' => $this->getType(),
      'status' => $this->getStatusCode(),
      'message' => $this->getMessage(),
      'returned object' => $returnedObject ? $returnedObject->getAll() : NULL,
      'context' => $this->getContextAsArray(),
    ];
  }

  public function getContextAsArray(): array {
    return parent::getContextAsArray();
  }

  /**
   * @return \Civi\Osdi\LocalObject\LocalObjectInterface|\Civi\Osdi\RemoteObjectInterface|null
   */
  public function getReturnedObject() {
    return $this->returnedObject;
  }

  /**
   * @param \Civi\Osdi\LocalObject\LocalObjectInterface|\Civi\Osdi\RemoteObjectInterface|null $returnedObject
   *
   * @return self
   */
  public function setReturnedObject($returnedObject): self {
    $this->returnedObject = $returnedObject;
    return $this;
  }

}