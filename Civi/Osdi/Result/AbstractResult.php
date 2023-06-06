<?php

namespace Civi\Osdi\Result;

abstract class AbstractResult implements \Civi\Osdi\ResultInterface {

  protected string $caller;
  protected $context = NULL;
  protected ?string $message = NULL;
  protected ?string $statusCode = NULL;
  protected ?string $type = NULL;

  public function __construct() {
    $stack = debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT |
      DEBUG_BACKTRACE_IGNORE_ARGS, 3);
    foreach ([$stack[1], $stack[2]] as $caller) {
      $callerClass = $caller['class'] ?? '';
      if ('Civi\\Osdi\\Result' === substr($callerClass, 0, 16)) {
        continue;
      }
      $callerClass = empty($callerClass) ? '' : $callerClass . '::';
      $this->caller = $callerClass . $caller['function'] ?? '';
    }
  }

  public function getCaller(): string {
    return $this->caller;
  }

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
    $context = $this->getContext();

    if (empty($context)) {
      return [];
    }

    $arrayify = function ($x) {
      if (is_object($x)) {
        if (method_exists($x, 'toArray')) {
          return $x->toArray();
        }
        if (method_exists($x, 'getArrayCopy')) {
          return $x->getArrayCopy();
        }
        if (method_exists($x, 'getArrayForCreate')) {
          return $x->getArrayForCreate();
        }
      }
      return $x;
    };

    if (is_array($context)) {
      return array_map($arrayify, $context);
    }

    $return = $arrayify($context);

    return is_array($return) ? $return : [$return];
  }

  public function isStatus(string $statusCode): bool {
    return $statusCode === $this->getStatusCode();
  }

  public function setCaller(string $caller): self {
    $this->caller = $caller;
    return $this;
  }

  public function toArray(): array {
    $prefixLength = strlen('\\Civi\\Osdi\\Result');
    return [
      'caller' => $this->caller,
      'type' => substr($this->getType(), $prefixLength),
      'status' => $this->getStatusCode(),
      'message' => $this->getMessage(),
      'context' => $this->getContextAsArray(),
    ];
  }

}