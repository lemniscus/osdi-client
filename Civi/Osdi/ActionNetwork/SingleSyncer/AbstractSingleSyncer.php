<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer;

use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\LocalObjectInterface;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\MapperInterface;
use Civi\Osdi\MatcherInterface;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\Result\FetchOldOrFindNewMatch as OldOrNewMatchResult;
use Civi\Osdi\Result\MapAndWrite as MapAndWriteResult;
use Civi\Osdi\Result\Sync as SyncResult;
use Civi\Osdi\Result\SyncEligibility;

abstract class AbstractSingleSyncer implements \Civi\Osdi\SingleSyncerInterface {

  protected array $syncProfile = [];

  protected RemoteSystemInterface $remoteSystem;

  protected ?MatcherInterface $matcher = NULL;

  protected ?MapperInterface $mapper = NULL;

  public function getMapper(): MapperInterface {
    if (empty($this->mapper)) {
      $mapperClass = $this->getSyncProfile()['mapper'];
      $this->mapper = new $mapperClass($this->getRemoteSystem());
    }
    return $this->mapper;
  }

  public function setMapper(?MapperInterface $mapper): AbstractSingleSyncer {
    $this->mapper = $mapper;
    return $this;
  }

  public function getMatcher(): MatcherInterface {
    if (empty($this->matcher)) {
      $matcherClass = $this->getSyncProfile()['matcher'];
      $this->matcher = new $matcherClass($this->getRemoteSystem());
    }
    return $this->matcher;
  }

  public function setMatcher(?MatcherInterface $matcher): self {
    $this->matcher = $matcher;
    return $this;
  }

  public function getRemoteSystem(): RemoteSystemInterface {
    return $this->remoteSystem;
  }

  public function setRemoteSystem(RemoteSystemInterface $remoteSystem): self {
    $this->remoteSystem = $remoteSystem;
    return $this;
  }

  public function getSyncProfile(): array {
    return $this->syncProfile;
  }

  public function setSyncProfile(array $syncProfile): self {
    $this->syncProfile = $syncProfile;
    return $this;
  }

  public function matchAndSyncIfEligible($originObject): LocalRemotePair {
    if (is_a($originObject, LocalObjectInterface::class)) {
      $pair = $this->toLocalRemotePair($originObject)
        ->setOrigin(LocalRemotePair::ORIGIN_LOCAL);
    }
    elseif (is_a($originObject, RemoteObjectInterface::class)) {
      $pair = $this->toLocalRemotePair(NULL, $originObject)
        ->setOrigin(LocalRemotePair::ORIGIN_REMOTE);
    }
    else {
      throw new InvalidArgumentException('Expected LocalObject or RemoteObject');
    }

    $result = new SyncResult();

    $this->fetchOldOrFindNewMatch($pair);

    if ($this->getSyncEligibility($pair)->isStatus(SyncEligibility::ELIGIBLE)) {
      $mapAndWrite = $this->oneWayMapAndWrite($pair);
      $statusCode = $mapAndWrite->isError() ? $result::ERROR : $result::SUCCESS;
    }
    else {
      $statusCode = $result::NO_SYNC_NEEDED;
    }

    $result->setStatusCode($statusCode);
    $result->setState($this->saveSyncStateIfNeeded($pair));
    $pair->getResultStack()->push($result);
    return $pair;
  }

  public function oneWayMapAndWrite(LocalRemotePair $pair): MapAndWriteResult {
    if (empty($pair->getOriginObject())) {
      throw new InvalidArgumentException();
    }

    if (is_null($pair->getLocalObject())) {
      $pair->setLocalObject($this->makeLocalObject());
    }
    if (is_null($pair->getRemoteObject())) {
      $pair->setRemoteObject($this->makeRemoteObject());
    }

    $result = new MapAndWriteResult();

    $originObject = $pair->getOriginObject();

    if ($originObject->getId()) {
      $originObject->loadOnce();
    }

    $mapResult = $this->getMapper()->mapOneWay($pair);
    $targetObject = $pair->getTargetObject();

    if ($mapResult->isError()) {
      $result->setStatusCode($result::ERROR)->setMessage($mapResult->getMessage());
    }
    elseif ($mapResult->isStatus($mapResult::SKIPPED_ALL_CHANGES)) {
      $result->setStatusCode(MapAndWriteResult::SKIPPED_CHANGES)
        ->setMessage($mapResult->getMessage());
    }
    elseif (!$targetObject->isAltered()) {
      $result->setStatusCode(MapAndWriteResult::NO_CHANGES_TO_WRITE);
    }
    elseif ($targetObject->getId()) {
      $this->persistTargetChanges($pair, $result);
    }
    else {
      $this->persistNewTarget($pair, $result);
    }

    $pair->getResultStack()->push($result);

    return $result;
  }

  protected function getSavedMatch(LocalRemotePair $pair) {
    return NULL;
  }

  protected function saveSyncStateIfNeeded(LocalRemotePair $pair) {
    return NULL;
  }

  public function toLocalRemotePair(
    LocalObjectInterface $localObject = NULL,
    RemoteObjectInterface $remoteObject = NULL
  ): LocalRemotePair {
    $pair = new LocalRemotePair($localObject, $remoteObject);
    $pair->setLocalClass($this->getLocalObjectClass());
    $pair->setRemoteClass($this->getRemoteObjectClass());
    return $pair;
  }

  public function fetchOldOrFindNewMatch(LocalRemotePair $pair): OldOrNewMatchResult {
    if (!empty($pair->getTargetObject())) {
      throw new InvalidArgumentException('Parameter to %s must be a ' .
        '%s containing only the origin object', __FUNCTION__, LocalRemotePair::class);
    }

    $resultStack = $pair->getResultStack();
    $currentResult = new OldOrNewMatchResult();

    if ($savedMatch = $this->getSavedMatch($pair)) {
      if ($pair->isOriginLocal()) {
        $pair->setRemoteObject($savedMatch->getRemoteObject());
      }
      else {
        $pair->setLocalObject($savedMatch->getLocalObject());
      }
      $currentResult->setStatusCode($currentResult::FETCHED_SAVED_MATCH);
      $resultStack->push($currentResult);
      return $currentResult;
    }

    $matchFindResult = $this->getMatcher()->tryToFindMatchFor($pair);
    $resultStack->push($matchFindResult);

    if ($matchFindResult->isError()) {
      $currentResult->setStatusCode($currentResult::ERROR);
      $currentResult->setMessage('Error when trying to find new match');
    }
    elseif ($matchFindResult->gotMatch()) {
      $currentResult->setStatusCode($currentResult::FOUND_NEW_MATCH);
      $pair->setTargetObject($matchFindResult->getMatch());
    }
    else {
      $currentResult->setStatusCode($currentResult::NO_MATCH_FOUND);
    }

    $resultStack->push($currentResult);
    return $currentResult;
  }

  protected function getSyncEligibility(LocalRemotePair $pair): SyncEligibility {
    $result = new SyncEligibility();
    $result->setStatusCode(SyncEligibility::ELIGIBLE);
    $pair->getResultStack()->push($result);
    return $result;
  }

  protected function persistNewTarget(LocalRemotePair $pair, MapAndWriteResult $result): void {
    $saveResult = $pair->getTargetObject()->trySave();
    $result->setSaveResult($saveResult);
    $result->setStatusCode(
      $saveResult->isError()
        ? MapAndWriteResult::SAVE_ERROR
        : MapAndWriteResult::WROTE_NEW);
  }

  protected function persistTargetChanges(LocalRemotePair $pair, MapAndWriteResult $result): void {
    $saveResult = $pair->getTargetObject()->trySave();
    $result->setSaveResult($saveResult);
    $result->setStatusCode(
      $saveResult->isError()
        ? MapAndWriteResult::SAVE_ERROR
        : MapAndWriteResult::WROTE_CHANGES);
  }

  abstract protected function getLocalObjectClass(): string;

  abstract protected function getRemoteObjectClass(): string;

}
