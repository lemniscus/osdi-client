<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer\Tag;

use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\LocalObject\LocalObjectInterface;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\MapperInterface;
use Civi\Osdi\MatcherInterface;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\Result\FetchOldOrFindNewMatch as OldOrNewMatchResult;
use Civi\Osdi\Result\MapAndWrite as MapAndWriteResult;
use Civi\Osdi\Result\Sync as SyncResult;
use Civi\Osdi\Result\SyncEligibility;
use Civi\Osdi\SingleSyncerInterface;
use Civi\Osdi\Util;

class Basic implements SingleSyncerInterface {

  private array $syncProfile = [];

  private RemoteSystemInterface $remoteSystem;

  private ?MatcherInterface $matcher = NULL;

  private ?MapperInterface $mapper = NULL;

  public function __construct(RemoteSystemInterface $remoteSystem) {
    $this->remoteSystem = $remoteSystem;
  }

  public function getMapper(): MapperInterface {
    if (empty($this->mapper)) {
      $mapperClass = $this->getSyncProfile()['mapper'];
      $this->mapper = new $mapperClass($this->getRemoteSystem());
    }
    return $this->mapper;
  }

  public function setMapper($mapper): void {
    $this->mapper = $mapper;
  }

  public function getMatcher(): MatcherInterface {
    if (empty($this->matcher)) {
      $matcherClass = $this->getSyncProfile()['matcher'];
      $this->matcher = new $matcherClass($this->getRemoteSystem());
    }
    return $this->matcher;
  }

  public function setMatcher($matcher): void {
    $this->matcher = $matcher;
  }

  public function getRemoteSystem(): RemoteSystemInterface {
    return $this->remoteSystem;
  }

  public function setRemoteSystem(RemoteSystemInterface $system): void {
    $this->remoteSystem = $system;
  }

  public function getSavedMatch(
    LocalRemotePair $pair,
    int $syncProfileId = NULL
  ): ?LocalRemotePair {
    $side = $pair->isOriginLocal() ? 'local' : 'remote';
    $objectId = $pair->getOriginObject()->getId();
    $syncProfileId = $syncProfileId ?? $this->getSyncProfile()['id'] ?? 'null';
    $savedMatches = self::getOrSetAllSavedMatches()[$syncProfileId] ?? [];
    return $savedMatches[$side][$objectId] ?? NULL;
  }

  public function getSyncProfile(): array {
    return $this->syncProfile;
  }

  public function setSyncProfile(array $syncProfile): void {
    $this->syncProfile = $syncProfile;
  }

  /**
   * @param \Civi\Osdi\LocalObject\LocalObjectInterface|\Civi\Osdi\RemoteObjectInterface $originObject
   *
   * @return \Civi\Osdi\LocalRemotePair
   */
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
    $targetObject = $pair->getTargetObject();

    if ($originObject->getId()) {
      $originObject->loadOnce();
    }

    $mapResult = $this->getMapper()->mapOneWay($pair);

    if ($mapResult::SKIPPED_ALL_CHANGES === $mapResult->getStatusCode()) {
      $result->setStatusCode(MapAndWriteResult::SKIPPED_CHANGES)
        ->setMessage($mapResult->getMessage());
    }
    elseif (!$targetObject->isAltered()) {
      $result->setStatusCode(MapAndWriteResult::NO_CHANGES_TO_WRITE);
    }
    elseif ($targetObject->getId()) {
      $this->oneWayMapAndWriteUpdate($pair, $result);
    }
    else {
      $this->oneWayMapAndWriteCreate($pair, $result);
    }

    $pair->getResultStack()->push($result);

    return $result;
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

  protected function fetchOldOrFindNewMatch(LocalRemotePair $pair): OldOrNewMatchResult {
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

  protected function getLocalObjectClass(): string {
    return \Civi\Osdi\LocalObject\Tag::class;
  }

  protected function getRemoteObjectClass(): string {
    return \Civi\Osdi\ActionNetwork\Object\Tag::class;
  }

  protected function getSyncEligibility(LocalRemotePair $pair): SyncEligibility {
    $result = new SyncEligibility();
    $result->setStatusCode(SyncEligibility::ELIGIBLE);
    $pair->getResultStack()->push($result);
    return $result;
  }

  protected function makeLocalObject($id = NULL): \Civi\Osdi\LocalObject\Tag {
    return new \Civi\Osdi\LocalObject\Tag($id);
  }

  protected function makeRemoteObject($id = NULL): \Civi\Osdi\ActionNetwork\Object\Tag {
    $tag = new \Civi\Osdi\ActionNetwork\Object\Tag($this->getRemoteSystem());
    if ($id) {
      $tag->setId($id);
    }
    return $tag;
  }

  protected function oneWayMapAndWriteCreate(LocalRemotePair $pair, MapAndWriteResult $result): void {
    $saveResult = $pair->getTargetObject()->trySave();
    $result->setSaveResult($saveResult);
    $result->setStatusCode(
      $saveResult->isError()
        ? MapAndWriteResult::SAVE_ERROR
        : MapAndWriteResult::WROTE_NEW);
  }

  protected function oneWayMapAndWriteUpdate(LocalRemotePair $pair, MapAndWriteResult $result): void {
    $saveResult = $pair->getTargetObject()->trySave();
    $result->setSaveResult($saveResult);
    $result->setStatusCode(
      $saveResult->isError()
        ? MapAndWriteResult::SAVE_ERROR
        : MapAndWriteResult::WROTE_CHANGES);
  }

  public function saveMatch(LocalRemotePair $pair): LocalRemotePair {
    $localObject = $pair->getLocalObject();
    $remoteObject = $pair->getRemoteObject();
    $localId = $localObject->getId();
    $remoteId = $remoteObject->getId();
    $savedMatches = $this->getOrSetAllSavedMatches();
    $syncProfileId = $this->getSyncProfile()['id'] ?? 'null';

    if ($oldMatchForLocal = $savedMatches[$syncProfileId]['local'][$localId] ?? NULL) {
      $oldMatchRemoteId = $oldMatchForLocal->getRemoteObject()->getId();
      unset($savedMatches[$syncProfileId]['remote'][$oldMatchRemoteId]);
      unset($savedMatches['persistable'][$syncProfileId][$localId]);
    }
    if ($oldMatchForRemote = $savedMatches[$syncProfileId]['remote'][$remoteId] ?? NULL) {
      $oldMatchLocalId = $oldMatchForRemote->getLocalObject()->getId();
      unset($savedMatches[$syncProfileId]['local'][$oldMatchLocalId]);
      unset($savedMatches['persistable'][$syncProfileId][$oldMatchLocalId]);
    }

    $pair = new LocalRemotePair($localObject, $remoteObject);
    $savedMatches[$syncProfileId]['local'][$localId] = $pair;
    $savedMatches[$syncProfileId]['remote'][$remoteId] = $pair;
    $savedMatches['persistable'][$syncProfileId][$localId] = $remoteId;

    $this->getOrSetAllSavedMatches($savedMatches);
    return $pair;
  }

  /**
   * @param \Civi\Osdi\LocalRemotePair $pair
   *
   * @return \Civi\Osdi\LocalRemotePair
   */
  protected function saveSyncStateIfNeeded(LocalRemotePair $pair) {
    /** @var \Civi\Osdi\Result\FetchOldOrFindNewMatch $r */
    $r = $pair->getResultStack()->getLastOfType(OldOrNewMatchResult::class);
    if ($r->isStatus($r::FETCHED_SAVED_MATCH)) {
      return $pair;
    }
    return $this->saveMatch($pair);
  }

  protected function typeCheckLocalObject(LocalObjectInterface $object): \Civi\Osdi\LocalObject\Tag {
    Util::assertClass($object, \Civi\Osdi\LocalObject\Tag::class);
    /** @var \Civi\Osdi\LocalObject\Tag $object */
    return $object;
  }

  protected function typeCheckRemoteObject(RemoteObjectInterface $object): \Civi\Osdi\ActionNetwork\Object\Tag {
    Util::assertClass($object, \Civi\Osdi\ActionNetwork\Object\Tag::class);
    /** @var \Civi\Osdi\ActionNetwork\Object\Tag $object */
    return $object;
  }

  private function getOrSetAllSavedMatches($replacement = NULL): array {
    static $matchArray = NULL;

    if (is_array($replacement)) {
      $matchArray = $replacement;
      \Civi::cache('long')
        ->set('osdi-client:tag-match', $matchArray['persistable']);
    }

    elseif (is_null($matchArray)) {
      $idArray = \Civi::cache('long')->get('osdi-client:tag-match', []);
      $matchArray = ['persistable' => $idArray];
      foreach ($idArray as $syncProfileId => $matches) {
        foreach ($matches as $localId => $remoteId) {
          $localObject = $this->makeLocalObject($localId);
          $remoteObject = $this->makeRemoteObject($remoteId);
          $pair = new LocalRemotePair($localObject, $remoteObject);
          $matchArray[$syncProfileId]['local'][$localId] = $pair;
          $matchArray[$syncProfileId]['remote'][$remoteId] = $pair;
        }
      }
    }
    return $matchArray;
  }

}
