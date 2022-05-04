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

  public function getLocalObject(): ?LocalObjectInterface {
    return $this->localObject;
  }

  public function getRemoteObject(): ?RemoteObjectInterface {
    return $this->remoteObject;
  }

  public function isError(): bool {
    return $this->isError;
  }

  public function getMessage(): ?string {
    return $this->message;
  }

  public function getSavedMatch(): ?array {
    return $this->savedMatch;
  }

  public function getMatchResult(): ? MatchResult {
    return $this->matchResult;
  }

  public function getSyncResult(): ?SyncResult {
    return $this->syncResult;
  }

}
