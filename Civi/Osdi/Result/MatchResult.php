<?php

namespace Civi\Osdi\Result;

use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\LocalObjectInterface;
use Civi\Osdi\RemoteObjectInterface;

class MatchResult extends AbstractResult implements \Civi\Osdi\ResultInterface {

  const ERROR_INDETERMINATE = 'provided criteria do not uniquely identify a record';

  const ERROR_INVALID_ID = 'invalid contact id';

  const ERROR_MISC = 'miscellaneous error';

  const ERROR_MISSING_DATA = 'one or more required fields are missing from the source contact';

  const FOUND_MATCH = 'found match';

  const NO_MATCH = 'no match found';

  const ORIGIN_LOCAL = 'local';

  const ORIGIN_REMOTE = 'remote';

  protected ?LocalObjectInterface $localObject;

  protected ?RemoteObjectInterface $remoteObject;

  protected ?string $origin;

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
    parent::__construct();
  }

  /**
   * @return \Civi\Osdi\LocalObjectInterface|\Civi\Osdi\RemoteObjectInterface|null
   */
  public function getMatch() {
    if (self::ORIGIN_LOCAL === $this->origin) {
      return $this->remoteObject;
    }
    return $this->localObject;
  }

  /**
   * @param \Civi\Osdi\LocalObjectInterface|\Civi\Osdi\RemoteObjectInterface $object
   *
   * @return $this
   */
  public function setMatch($object): self {
    if (self::ORIGIN_LOCAL === $this->origin) {
      return $this->setRemoteObject($object);
    }
    return $this->setLocalObject($object);
  }

  /**
   * @return \Civi\Osdi\LocalObjectInterface|\Civi\Osdi\RemoteObjectInterface|null
   */
  public function getOriginObject() {
    if (self::ORIGIN_LOCAL === $this->origin) {
      return $this->localObject;
    }
    return $this->remoteObject;
  }

  /**
   * @param \Civi\Osdi\LocalObjectInterface|\Civi\Osdi\RemoteObjectInterface $object
   *
   * @return $this
   */
  public function setOriginObject($object): self {
    if (self::ORIGIN_LOCAL === $this->origin) {
      return $this->setLocalObject($object);
    }
    return $this->setRemoteObject($object);
  }

  public function isError(): bool {
    return in_array(
      $this->statusCode,
      [
        self::ERROR_INDETERMINATE,
        self::ERROR_INVALID_ID,
        self::ERROR_MISC,
        self::ERROR_MISSING_DATA,
      ]
    );
  }

  public function getOrigin(): string {
    return $this->origin;
  }

  public function gotMatch() {
    return !empty($this->getMatch());
  }

  public function toArray(): array {
    $localObject = $this->getLocalObject();
    $remoteObject = $this->getRemoteObject();
    return [
      'type' => $this->getType(),
      'status' => $this->getStatusCode(),
      'message' => $this->getMessage(),
      'localObject id' => $localObject ? $localObject->getId() : NULL,
      'remoteObject id' => $remoteObject ? $remoteObject->getId() : NULL,
      'context' => $this->getContextAsArray(),
    ];
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

}
