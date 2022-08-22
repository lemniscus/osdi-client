<?php

namespace Civi\Osdi\Result;

abstract class AbstractResult implements \Civi\Osdi\ResultInterface {

  protected $context = NULL;
  protected ?string $message = NULL;
  protected ?string $statusCode = NULL;
  protected ?string $type = NULL;

  /**
   * @return mixed
   */
  public function getContext() {
    return $this->context;
  }

  /**
   * @param mixed $context
   *
   * @return static
   */
  public function setContext($context): self {
    $this->context = $context;
    return $this;
  }

  public function getMessage(): ?string {
    return $this->message;
  }

  public function setMessage(?string $message): self {
    $this->message = $message;
    return $this;
  }

  public function getStatusCode(): ?string {
    return $this->statusCode;
  }

  public function setStatusCode(?string $statusCode): self {
    $this->statusCode = $statusCode;
    return $this;
  }

  public function getType(): string {
    return $this->type ?? static::class;
  }

  public function setType(string $type): self {
    $this->type = $type;
    return $this;
  }

  public function getContextAsArray(): array {
    return [$this->getContext()];
  }

  public function isStatus(string $statusCode): bool {
    return $statusCode === $this->getStatusCode();
  }

  public function toArray(): array {
    return [
      'type' => $this->getType(),
      'status' => $this->getStatusCode(),
      'message' => $this->getMessage(),
      'context' => $this->getContextAsArray(),
    ];
  }

}