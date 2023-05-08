<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer\Person;

use Civi\Api4\OsdiDeletion;
use Civi\Api4\OsdiFlag;
use Civi\Osdi\ActionNetwork\SingleSyncer\AbstractSingleSyncer;
use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\Container;
use Civi\Osdi\LocalObjectInterface;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Logger;
use Civi\Osdi\MapperInterface;
use Civi\Osdi\MatcherInterface;
use Civi\Osdi\PersonSyncState;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\Result\DeletionSync as DeletionSyncResult;
use Civi\Osdi\Result\FetchOldOrFindNewMatch as OldOrNewMatchResult;
use Civi\Osdi\Result\MapAndWrite as MapAndWriteResult;
use Civi\Osdi\Result\MatchResult as MatchResult;
use Civi\Osdi\Result\Sync;
use Civi\Osdi\Result\SyncEligibility;
use Civi\Osdi\SingleSyncerInterface;
use Civi\Osdi\Util;
use Civi\OsdiClient;

class PersonBasic extends AbstractSingleSyncer implements SingleSyncerInterface {

  use PersonLocalRemotePairTrait;

  protected ?MapperInterface $mapper = NULL;

  protected ?MatcherInterface $matcher = NULL;

  protected RemoteSystemInterface $remoteSystem;

  protected array $syncProfile = [];

  /**
   * @var int|null
   * @deprecated
   */
  private ?int $syncProfileId = NULL;

  public function __construct(RemoteSystemInterface $remoteSystem) {
    $this->remoteSystem = $remoteSystem;
  }

  /**
   * Try to find a PersonSyncState for the origin Person. If one exists, try to
   * use it to fill in empty slots in $pair. If that doesn't work, use our
   * Matcher to try to find a new match. If one is found, add the target object
   * to $pair.
   *
   * @throws \Civi\Osdi\Exception\InvalidArgumentException
   */
  public function fetchOldOrFindNewMatch(LocalRemotePair $pair): OldOrNewMatchResult {
    $result = new OldOrNewMatchResult();

    $syncProfileId = OsdiClient::container()->getSyncProfileId();

    try {
      $syncState = $pair->isOriginLocal()
        ? PersonSyncState::getForLocalPerson($pair->getLocalObject(), $syncProfileId)
        : PersonSyncState::getForRemotePerson($pair->getRemoteObject(), $syncProfileId);
    }
    catch (\Throwable $e) {
      $result->setMessage('error retrieving sync state');
      return $this->pushResult($pair, $result, $result::ERROR);
    }

    if ($this->fillLocalRemotePairFromSyncState($pair, $syncState)) {
      $result->setSavedMatch($syncState);
      return $this->pushResult($pair, $result, $result::FETCHED_SAVED_MATCH);
    }

    $pair->getOriginObject()->loadOnce();
    $matchResult = $this->getMatcher()->tryToFindMatchFor($pair);

    if ($matchResult->isError()) {
      $result->setStatusCode($result::ERROR)->setMessage('error finding match');
    }

    elseif (MatchResult::NO_MATCH == $matchResult->getStatusCode()) {
      $result->setStatusCode($result::NO_MATCH_FOUND);
    }

    else {
      $pair->setTargetObject($matchResult->getMatch()->loadOnce());
      $result->setStatusCode($result::FOUND_NEW_MATCH);
    }

    $pair->getResultStack()->push($result);
    return $result;
  }

  protected function getSyncEligibility(LocalRemotePair $pair): SyncEligibility {
    $result = new SyncEligibility();

    if ($this->originHasErrorFlags($pair)) {
      $result->setMessage('origin record was flagged with an error');
      return $this->pushResult($pair, $result, $result::INELIGIBLE);
    }

    if ($pair->isOriginRemote() && $this->wasDeletedByUs($pair)) {
      $result->setMessage('origin record was deleted by us');
      return $this->pushResult($pair, $result, $result::INELIGIBLE);
    }

    $syncState = $pair->getPersonSyncState();
    if (empty($syncState)) {
      $result->setMessage('no previous sync history');
      return $this->pushResult($pair, $result, $result::ELIGIBLE);
    }

    if ($pair->isOriginLocal()) {
      $pair->getLocalObject()->loadOnce();
    }

    $modTimeAfterLastSync = $pair->isOriginLocal() ?
      $syncState->getLocalPostSyncModifiedTime() :
      $syncState->getRemotePostSyncModifiedTime();
    $noPreviousSync = empty($modTimeAfterLastSync);
    $currentModTime = $this->modTimeAsUnixTimestamp($pair->getOriginObject());
    $isModifiedSinceLastSync = $currentModTime > $modTimeAfterLastSync;

    if ($noPreviousSync || $isModifiedSinceLastSync) {
      $result->setMessage(trim(($noPreviousSync ? 'no previous sync history. ' : '') .
        ($isModifiedSinceLastSync ? 'modified since last sync.' : '')));
      return $this->pushResult($pair, $result, $result::ELIGIBLE);
    }

    $result->setMessage('Sync is already up to date');
    return $this->pushResult($pair, $result, $result::NOT_NEEDED);
  }

  public function makeLocalObject($id = NULL): LocalObjectInterface {
    return \Civi\OsdiClient::container()->make('LocalObject', 'Person', $id);
  }

  public function makeRemoteObject($id = NULL): RemoteObjectInterface {
    $system = $this->getRemoteSystem();
    $person = \Civi\OsdiClient::container()->make('OsdiObject', 'osdi:people', $system);
    if (!is_null($id)) {
      $person->setId($id);
    }
    return $person;
  }

  public function oneWayMapAndWrite(LocalRemotePair $pair): MapAndWriteResult {
    $pairBeforeMapAndWrite = clone $pair;
    $result = parent::oneWayMapAndWrite($pair);
    $result->setPairBefore($pairBeforeMapAndWrite);
    return $result;
  }

  /**
   * Create a persistent record of the given Person pair, along with information
   * about the current time, modification times, status, etc, which may be useful
   * for future sync processes and error handling. If we've written an active
   * Person to Action Network, make sure we're not counting them as deleted.
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   * @throws \Civi\Osdi\Exception\InvalidArgumentException
   */
  protected function savePairToSyncState(LocalRemotePair $pairAfter): PersonSyncState {
    $localPersonAfter = $pairAfter->getLocalObject();
    $remotePersonAfter = $pairAfter->getRemoteObject();

    $lastMapAndWriteResult = $pairAfter->getResultStack()
      ->getLastOfType(MapAndWriteResult::class);

    if ($lastMapAndWriteResult) {
      $pairBefore = $lastMapAndWriteResult->getPairBefore();

      $localPersonBefore = $pairBefore->getLocalObject();
      $remotePersonBefore = $pairBefore->getRemoteObject();
    }

    $syncState = new PersonSyncState();
    $syncProfileId = OsdiClient::container()->getSyncProfileId();
    $syncState->setSyncProfileId($syncProfileId);
    $syncState->setSyncTime(time());
    $syncState->setContactId(
      $localPersonAfter ? $localPersonAfter->getId() : NULL);
    $syncState->setRemotePersonId(
      $remotePersonAfter ? $remotePersonAfter->getId() : NULL);

    $syncState->setSyncOrigin(
      $pairAfter->isOriginLocal() ?
        PersonSyncState::ORIGIN_LOCAL : PersonSyncState::ORIGIN_REMOTE);

    if (isset($remotePersonBefore)) {
      $syncState->setRemotePreSyncModifiedTime(
        $this->modTimeAsUnixTimestamp($remotePersonBefore));
    }

    if (isset($remotePersonAfter)) {
      $syncState->setRemotePostSyncModifiedTime(
        $this->modTimeAsUnixTimestamp($remotePersonAfter));
    }

    if (isset($localPersonBefore)) {
      $syncState->setLocalPreSyncModifiedTime(
        $this->modTimeAsUnixTimestamp($localPersonBefore));
    }

    if (isset($localPersonAfter)) {
      $syncState->setLocalPostSyncModifiedTime(
        $this->modTimeAsUnixTimestamp($localPersonAfter));
    }

    $lastResult = $pairAfter->getLastResult();
    if ($lastResult) {
      $syncState->setSyncStatus(
        get_class($lastResult) . '::' . $lastResult->getStatusCode());
    }

    $syncState->save();

    if ($pairAfter->isOriginLocal() && $remotePersonAfter) {
      OsdiDeletion::delete(FALSE)
        ->addWhere('sync_profile_id', '=', $syncProfileId)
        ->addWhere('remote_object_id', '=', $remotePersonAfter->getId())
        ->execute();
    }

    return $syncState;
  }

  /**
   * If the pair is in an error state, or the pair has gone through a MapAndWrite
   * process, save information from the pair as a sync state record and return
   * the sync state object. Otherwise, if the pair was recreated from an
   * existing sync state object, return that. Otherwise, return NULL.
   *
   * @return \Civi\Osdi\PersonSyncState|null
   */
  protected function saveSyncStateIfNeeded(LocalRemotePair $pair): ?PersonSyncState {
    if ($pair->isError()) {
      return $this->savePairToSyncState($pair);
    }

    $resultStack = $pair->getResultStack();

    $r = $resultStack->getLastOfType(MapAndWriteResult::class);
    if ($r) {
      return $this->savePairToSyncState($pair);
    }

    $r = $resultStack->getLastOfType(OldOrNewMatchResult::class);
    if ($r && $r->isStatus($r::FETCHED_SAVED_MATCH)) {
      return $r->getSavedMatch();
    }

    return NULL;
  }

  public function syncDeletion(LocalRemotePair $pair): DeletionSyncResult {
    $result = new DeletionSyncResult();

    //
    //$matchResult = $this->getMatcher()->tryToFindMatchFor($pair);
    //$matchCode = $matchResult->getStatusCode();
    //
    //if ($matchResult::FOUND_MATCH === $matchCode) {
    //  $matchResult->getMatch()->delete();
    //  if ($pair->isOriginLocal()) {
    //    \Civi\Api4\OsdiDeletion::create(FALSE)
    //      ->addValue('sync_profile_id', $this->syncProfileId)
    //      ->addValue('remote_object_id', $matchResult->getMatch()->getId())
    //      ->execute();
    //  }
    //  $result->setStatusCode($result::DELETED);
    //}

    $oldOrNewMatch = $this->fetchOldOrFindNewMatch($pair);

    if (
      $oldOrNewMatch->isStatus($oldOrNewMatch::FETCHED_SAVED_MATCH)
      || $oldOrNewMatch->isStatus($oldOrNewMatch::FOUND_NEW_MATCH)
    ) {
      $pair->getTargetObject()->delete();
      if ($pair->isOriginLocal()) {
        $deletionRecord = [
          'sync_profile_id' => OsdiClient::container()->getSyncProfileId(),
          'remote_object_id' => $pair->getTargetObject()->getId(),
        ];
        \Civi\Api4\OsdiDeletion::save(FALSE)
          ->setMatch(['sync_profile_id', 'remote_object_id'])
          ->setRecords([$deletionRecord])
          ->execute();
      }
      $result->setStatusCode($result::DELETED);
    }

    //elseif ($matchResult::NO_MATCH === $matchCode) {

    elseif ($oldOrNewMatch->isStatus($oldOrNewMatch::NO_MATCH_FOUND)) {
      $result->setStatusCode($result::NOTHING_TO_DELETE);
    }
    else {
      $result->setStatusCode($result::ERROR);
    }

    $pair->getResultStack()->push($result);
    return $result;
  }

  public function makeLocalRemotePair(
    LocalObjectInterface $localObject = NULL,
    RemoteObjectInterface $remoteObject = NULL
  ): LocalRemotePair {
    $pair = new LocalRemotePair($localObject, $remoteObject);
    $pair->setLocalClass($this->getLocalObjectClass());
    $pair->setRemoteClass($this->getRemoteObjectClass());
    return $pair;
  }

  /**
   * @param \Civi\Osdi\CrudObjectInterface $person
   */
  protected function modTimeAsUnixTimestamp($person): ?int {
    if ($m = $person->modifiedDate->get()) {
      return strtotime($m);
    }
    return NULL;
  }

  protected function newRemoteShouldBeCreatedForLocal(LocalRemotePair $pair): bool {
    return TRUE;
  }

  private function originHasErrorFlags(LocalRemotePair $pair): int {
    $flags = OsdiFlag::get(FALSE)
      ->selectRowCount()
      ->addWhere('status', '=', OsdiFlag::STATUS_ERROR)
      ->addWhere(
        $pair->isOriginLocal() ? 'contact_id' : 'remote_object_id',
        '=',
        $pair->getOriginObject()->getId())
      ->execute();

    return $flags->count();
  }

  protected function getLocalObjectClass(): string {
    return \Civi\Osdi\LocalObject\PersonBasic::class;
  }

  protected function getRemoteObjectClass(): string {
    return \Civi\Osdi\ActionNetwork\Object\Person::class;
  }

  public function setMapper(?MapperInterface $mapper): self {
    $this->mapper = $mapper;
    return $this;
  }

  public function getMapper(): MapperInterface {
    if (empty($this->mapper)) {
      $this->mapper = OsdiClient::container()
        ->make('Mapper', 'Person', $this->getRemoteSystem());
    }
    return $this->mapper;
  }

  public function setMatcher(?MatcherInterface $matcher): self {
    $this->matcher = $matcher;
    return $this;
  }

  public function getMatcher(): MatcherInterface {
    if (empty($this->matcher)) {
      $this->matcher = OsdiClient::container()
        ->make('Matcher', 'Person', $this->getRemoteSystem());
    }
    return $this->matcher;
  }

  public function setRemoteSystem(RemoteSystemInterface $remoteSystem): self {
    $this->remoteSystem = $remoteSystem;
    return $this;
  }

  public function getRemoteSystem(): RemoteSystemInterface {
    return $this->remoteSystem;
  }

  /**
   *
   * @deprecated
   *
   * @param array $syncProfile
   *
   * @return $this
   */
  public function setSyncProfile(array $syncProfile): self {
    $this->syncProfile = $syncProfile;
    if (isset($syncProfile['id'])) {
      $this->syncProfileId = $syncProfile['id'];
    }
    if (isset($syncProfile['mapper'])) {
      $this->setMapper(new $syncProfile['mapper']($this->getRemoteSystem()));
    }
    if (isset($syncProfile['matcher'])) {
      $this->setMatcher(new $syncProfile['matcher']($this->getRemoteSystem()));
    }
    return $this;
  }

  public function getSyncProfile(): array {
    return $this->syncProfile;
  }

  private function wasDeletedByUs(LocalRemotePair $pair): int {
    $remoteId = $pair->getRemoteObject()->getId();
    if (empty($remoteId)) {
      return FALSE;
    }

    $syncProfileId = OsdiClient::container()->getSyncProfileId();
    $syncProfileClause =
      empty($syncProfileId)
        ? ['sync_profile_id', 'IS EMPTY']
        : ['sync_profile_id', '=', $syncProfileId];

    $deletionRecords = OsdiDeletion::get(FALSE)
      ->addWhere(...$syncProfileClause)
      ->addWhere('remote_object_id', '=', $remoteId)
      ->selectRowCount()->execute();

    $wasDeletedByUs = count($deletionRecords);
    return $wasDeletedByUs;
  }

  /**
   * If $pair doesn't already include both local and remote Person objects, attempt
   * to fill them in by loading them via the IDs recorded in the $state.
   */
  protected function fillLocalRemotePairFromSyncState(
    LocalRemotePair $pair,
    PersonSyncState $syncState
  ): bool {
    $pair->setPersonSyncState($syncState);

    if (empty($syncState->getContactId()) || empty($syncState->getRemotePersonId())) {
      return FALSE;
    }

    $localPerson = $pair->getLocalObject();
    $remotePerson = $pair->getRemoteObject();

    try {
      $container = OsdiClient::container();
      $localObject = $localPerson ??
        $container->make('LocalObject', 'Person',
          $syncState->getContactId())->loadOnce();

      /** @var \Civi\Osdi\ActionNetwork\Object\Person $remoteObject */
      $remoteObject = $remotePerson ??
        $container->callStatic('OsdiObject', 'osdi:people',
          'loadFromId', $syncState->getRemotePersonId());
    }
    catch (InvalidArgumentException | EmptyResultException $e) {
      $syncState->delete();
    }

    if (!is_null($localObject) && !is_null($remoteObject)) {
      $pair->setLocalObject($localObject)->setRemoteObject($remoteObject);
      return TRUE;
    }

    return FALSE;
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

}
