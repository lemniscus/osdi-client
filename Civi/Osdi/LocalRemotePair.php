<?php

namespace Civi\Osdi;

use Civi\Osdi\LocalObject\LocalObjectInterface;

class LocalRemotePair {

  private ?LocalObjectInterface $localObject;
  private ?RemoteObjectInterface $remoteObject;
  private bool $isError;
  private ?string $message;
  private ?array $savedMatch;
  private ?MatchResult $matchResult;
  private ?SyncResult $syncResult;

  /**
   * @param \Civi\Osdi\LocalObject\LocalObjectInterface|null $localObject
   * @param \Civi\Osdi\RemoteObjectInterface|null $remoteObject
   * @param bool $isError
   * @param string|null $message
   * @param array|null $savedMatch
   * @param \Civi\Osdi\MatchResult|null $matchResult
   * @param \Civi\Osdi\SyncResult|null $syncResult
   */
  public function __construct(
      LocalObjectInterface $localObject = NULL,
      RemoteObjectInterface $remoteObject = NULL,
      bool $isError = FALSE,
      string $message = NULL,
      array $savedMatch = NULL,
      MatchResult $matchResult = NULL,
      SyncResult $syncResult = NULL) {
    $this->localObject = $localObject;
    $this->remoteObject = $remoteObject;
    $this->isError = $isError;
    $this->message = $message;
    $this->savedMatch = $savedMatch;
    $this->matchResult = $matchResult;
    $this->syncResult = $syncResult;
  }

  public function getLocalObject() {
    return $this->localObject;
  }

  public function getRemoteObject() {
    return $this->remoteObject;
  }

  public function isError() {
    return $this->isError;
  }

  public function getMessage() {
    return $this->message;
  }

  public function getSavedMatch() {
    return $this->savedMatch;
  }

  public function getMatchResult() {
    return $this->matchResult;
  }

  public function getSyncResult() {
    return $this->syncResult;
  }

}