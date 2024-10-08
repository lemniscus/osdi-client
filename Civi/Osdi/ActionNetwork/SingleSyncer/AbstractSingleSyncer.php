<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer;

use Civi\Api4\OsdiLog;
use Civi\Osdi\CrudObjectInterface;
use Civi\Osdi\Exception\CannotMapException;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\LocalObjectInterface;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Logger;
use Civi\Osdi\MapperInterface;
use Civi\Osdi\MatcherInterface;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\Result\FetchOldOrFindNewMatch as OldOrNewMatchResult;
use Civi\Osdi\Result\MapAndWrite as MapAndWriteResult;
use Civi\Osdi\Result\Sync as SyncResult;
use Civi\Osdi\Result\SyncEligibility;
use Civi\Osdi\ResultInterface;
use Civi\OsdiClient;

abstract class AbstractSingleSyncer implements \Civi\Osdi\SingleSyncerInterface {

  protected array $syncProfile = [];

  protected RemoteSystemInterface $remoteSystem;

  protected ?MatcherInterface $matcher = NULL;

  protected ?MapperInterface $mapper = NULL;

  protected ?string $registryKey = NULL;

  protected bool $caching = FALSE;

  protected static string $remoteType;

  protected static string $localType;

  public function isCaching(): bool {
    return $this->caching;
  }

  public function setCaching(bool $objectCaching): AbstractSingleSyncer {
    $this->caching = $objectCaching;
    return $this;
  }

  public function getMapper(): MapperInterface {
    if (empty($this->mapper)) {
      $this->mapper = OsdiClient::container()->getSingle('Mapper', $this->registryKey);
    }
    return $this->mapper;
  }

  public function setMapper(?MapperInterface $mapper): self {
    $this->mapper = $mapper;
    return $this;
  }

  public function getMatcher(): MatcherInterface {
    if (empty($this->matcher)) {
      $this->matcher = OsdiClient::container()->getSingle('Matcher', $this->registryKey);
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

  public function matchAndSyncIfEligible(CrudObjectInterface $originObject): LocalRemotePair {
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

    $fetchOldOrFindNewMatchResult = $this->fetchOldOrFindNewMatch($pair);

    if ($fetchOldOrFindNewMatchResult->isError()) {
      $statusCode = $result::ERROR;
      $result->setMessage($fetchOldOrFindNewMatchResult->getMessage() ??
        $fetchOldOrFindNewMatchResult->getStatusCode());
    }
    else {
      $syncEligibility = $this->getSyncEligibility($pair);
      $result->setMessage($syncEligibility->getMessage());
      if ($syncEligibility->isStatus(SyncEligibility::INELIGIBLE)) {
        $statusCode = $result::INELIGIBLE;
      }
      elseif ($syncEligibility->isStatus(SyncEligibility::NOT_NEEDED)) {
        $statusCode = $result::NO_SYNC_NEEDED;
      }
      elseif ($syncEligibility->isStatus(SyncEligibility::ELIGIBLE)) {
        try {
          $mapAndWriteResult = $this->oneWayMapAndWrite($pair);
          $statusCode = $mapAndWriteResult->isError() ? $result::ERROR : $result::SUCCESS;
          $result->setMessage($mapAndWriteResult->getMessage() ??
            $mapAndWriteResult->getStatusCode());
        }
        catch (CannotMapException $e) {
          $statusCode = $result::INELIGIBLE;
          $result->setMessage($e->getMessage());
        }
      }
    }

    $result->setStatusCode($statusCode);
    // saveSyncStateIfNeeded() may need to use our result, so push it now
    $pair->pushResult($result);
    $result->setState($this->saveSyncStateIfNeeded($pair));
    $this->logSyncResult($pair, $result);
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

  /**
   * @param \Civi\Osdi\LocalRemotePair $pair
   *
   * @return null|\Civi\Osdi\LocalRemotePair|\Civi\Osdi\SyncStateInterface
   */
  protected function getSavedMatch(LocalRemotePair $pair) {
    return NULL;
  }

  protected function pushResult(
    LocalRemotePair $pair,
    ResultInterface $result,
    $statusCode = NULL
  ): ResultInterface {
    if (!is_null($statusCode)) {
      $result->setStatusCode($statusCode);
    }
    $pair->getResultStack()->push($result);
    return $result;
  }

  /**
   * Since this class doesn't implement the caching of matches/sync states,
   * just return NULL.
   */
  protected function saveSyncStateIfNeeded(LocalRemotePair $pair) {
    return NULL;
  }

  public function toLocalRemotePair(
    LocalObjectInterface $localObject = NULL,
    RemoteObjectInterface $remoteObject = NULL
  ): LocalRemotePair {
    $pair = new LocalRemotePair($localObject, $remoteObject);
    return $pair;
  }

  /**
   * Try to find a match for the origin object. The given pair must not already
   * contain a target object.
   *
   * @throws \Civi\Osdi\Exception\InvalidArgumentException
   */
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
      $currentResult->setSavedMatch($savedMatch);
      $currentResult->setStatusCode($currentResult::FETCHED_SAVED_MATCH);
      $resultStack->push($currentResult);
      return $currentResult;
    }

    $matchFindResult = $this->getMatcher()->tryToFindMatchFor($pair);

    if ($matchFindResult->isError()) {
      $currentResult->setStatusCode($currentResult::ERROR);
      $currentResult->setMessage('Error when trying to find new match: ' .
        $matchFindResult->getMessage() ?? $matchFindResult->getStatusCode());
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

  public function fetchOldOrFindAndSaveNewMatch(LocalRemotePair $pair): OldOrNewMatchResult {
    $result = $this->fetchOldOrFindNewMatch($pair);
    $this->saveSyncStateIfNeeded($pair);
    return $result;
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

  protected function logSyncResult(LocalRemotePair $pair, SyncResult $syncResult): void {
    try {
      $syncState = $syncResult->getState();
      if (is_object($syncState)
        && method_exists($syncState, 'getDbTable')
        && method_exists($syncState, 'getId')
      ) {
        $syncStateDbTable = $syncState->getDbTable();
        $syncStateId = $syncState->getId();
      }
      OsdiLog::create(FALSE)
        ->addValue('creator', static::class)
        ->addValue('entity_table', $syncStateDbTable ?? NULL)
        ->addValue('entity_id', $syncStateId ?? NULL)
        ->addValue('details', $pair->getResultStack()->toArray())
        ->execute();
    }
    catch (\Throwable $e) {
      $context = ['exception' => $e, 'LocalRemotePair' => $pair];
      Logger::logError('Error writing to OsdiLog table', $context);
    }
  }

  public function makeRemoteObject($id = NULL): RemoteObjectInterface {
    $system = $this->getRemoteSystem();
    $object = \Civi\OsdiClient::container()
      ->make('OsdiObject', static::$remoteType, $system);
    if (!is_null($id)) {
      $object->setId($id);
    }
    return $object;
  }

  public function makeLocalObject($id = NULL): LocalObjectInterface {
    return \Civi\OsdiClient::container()
      ->make('LocalObject', static::$localType, $id);
  }

}
