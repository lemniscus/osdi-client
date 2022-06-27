<?php

namespace Civi\Osdi;

use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\LocalObject\LocalObjectInterface;

class MatchResult {

  const ERROR_INDETERMINATE = 'provided criteria do not uniquely identify a record';

  const ERROR_INVALID_ID = 'invalid contact id';

  const ERROR_MISSING_DATA = 'one or more required fields are missing from the source contact';

  const NO_MATCH = 'no match found';

  const ORIGIN_LOCAL = 'local';

  const ORIGIN_REMOTE = 'remote';

  protected ?LocalObjectInterface $localObject;

  protected ?RemoteObjectInterface $remoteObject;

  protected ?string $origin;

  protected ?string $statusCode;

  protected ?string $message;

  /**
   * @var mixed
   */
  protected $context;

  public function __construct(string $origin,
                              LocalObjectInterface $localObject = NULL,
                              RemoteObjectInterface $remoteObject = NULL,
                              string $statusCode = NULL,
                              string $message = NULL,
                              $context = NULL) {
    if (!in_array($origin, [self::ORIGIN_LOCAL, self::ORIGIN_REMOTE])) {
      throw new InvalidArgumentException('Invalid origin parameter given to '
        . __CLASS__ . '::' . __FUNCTION__ . ': %s', var_export($origin));
    }
    $this->origin = $origin;
    $this->localObject = $localObject;
    $this->remoteObject = $remoteObject;
    $this->statusCode = $statusCode;
    $this->message = $message;
    $this->context = $context;
  }

  /**
   * @return \Civi\Osdi\LocalObject\LocalObjectInterface|\Civi\Osdi\RemoteObjectInterface|null
   */
  public function getOriginObject() {
    if (self::ORIGIN_LOCAL === $this->origin) {
      return $this->localObject;
    }
    return $this->remoteObject;
  }

  /**
   * @return \Civi\Osdi\LocalObject\LocalObjectInterface|\Civi\Osdi\RemoteObjectInterface|null
   */
  public function getMatch() {
    if (self::ORIGIN_LOCAL === $this->origin) {
      return $this->remoteObject;
    }
    return $this->localObject;
  }

  public function isError(): bool {
    return in_array(
      $this->statusCode,
      [
        self::ERROR_INDETERMINATE,
        self::ERROR_INVALID_ID,
        self::ERROR_MISSING_DATA,
      ]
    );
  }

  public function getStatus(): ?string {
    return $this->statusCode;
  }

  public function getMessage(): ?string {
    return $this->message;
  }

  public function getContext() {
    return $this->context;
  }

  public function getLocalObject(): ?LocalObjectInterface {
    return $this->localObject;
  }

  public function getOrigin(): string {
    return $this->origin;
  }

  public function getRemoteObject(): ?RemoteObjectInterface {
    return $this->remoteObject;
  }

  public function gotMatch() {
    return !empty($this->getMatch());
  }

}
