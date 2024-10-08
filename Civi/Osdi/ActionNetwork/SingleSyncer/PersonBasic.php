<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer;

use Civi\Api4\OsdiDeletion;
use Civi\Api4\OsdiFlag;
use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\LocalObjectInterface;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\MapperInterface;
use Civi\Osdi\MatcherInterface;
use Civi\Osdi\PersonSyncState;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\Result\DeletionSync as DeletionSyncResult;
use Civi\Osdi\Result\FetchOldOrFindNewMatch as OldOrNewMatchResult;
use Civi\Osdi\Result\MapAndWrite as MapAndWriteResult;
use Civi\Osdi\Result\MatchResult as MatchResult;
use Civi\Osdi\Result\Sync as SyncResult;
use Civi\Osdi\Result\SyncEligibility;
use Civi\Osdi\SingleSyncerInterface;
use Civi\OsdiClient;

class PersonBasic extends AbstractSingleSyncer implements SingleSyncerInterface {

  protected static string $localType = 'Person';
  protected static string $remoteType = 'osdi:people';
  protected ?MapperInterface $mapper = NULL;
  protected ?MatcherInterface $matcher = NULL;
  protected RemoteSystemInterface $remoteSystem;

  public function __construct(?RemoteSystemInterface $remoteSystem = NULL) {
    $this->remoteSystem = $remoteSystem ?? OsdiClient::container()->getSingle(
      'RemoteSystem', 'ActionNetwork');
    $this->registryKey = 'Person';
  }

  /**
   * Try to find a PersonSyncState for the origin Person. If one exists, try to
   * use it to fill in empty slots in $pair. If that doesn't work, use our
   * Matcher to try to find a new match. If one is found, add the target object
   * to $pair.
   *
   * If $pair already includes both members, nothing will be replaced. However,
   * we still try to retrieve a PersonSyncState, and if there is a problem with
   * this, the result will be 'error'.
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
      $result->setContext(['exception' => $e]);
      return $this->pushResult($pair, $result, $result::ERROR);
    }

    if ($this->fillLocalRemotePairFromSyncState($pair, $syncState)) {
      $result->setSavedMatch($syncState);
      return $this->pushResult($pair, $result, $result::FETCHED_SAVED_MATCH);
    }

    $this->isCaching() ?
      $pair->setOriginObject($pair->getOriginObject()->getOrLoadCached()) :
      $pair->getOriginObject()->loadOnce();
    $matchResult = $this->getMatcher()->tryToFindMatchFor($pair);

    if ($matchResult->isError()) {
      $result->setStatusCode($result::ERROR)->setMessage(
        'Error when trying to find new match: ' .
        $matchResult->getMessage() ?? $matchResult->getStatusCode());
    }

    elseif (MatchResult::NO_MATCH == $matchResult->getStatusCode()) {
      $result->setStatusCode($result::NO_MATCH_FOUND);
    }

    else {
      if ($this->isCaching()) {
        $match = $matchResult->getMatch()->getOrLoadCached();
        $matchResult->setMatch($match);
      }
      else {
        $match = $matchResult->getMatch()->loadOnce();
      }
      $pair->setTargetObject($match);
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

    $syncState = $pair->getVar('PersonSyncState');
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
    $currentModTime = $this->modTimeAsTimestamp($pair->getOriginObject());
    $isModifiedSinceLastSync =
      strtotime($currentModTime ?? '') > strtotime($modTimeAfterLastSync ?? '');

    if ($noPreviousSync || $isModifiedSinceLastSync) {
      $result->setMessage(trim(($noPreviousSync ? 'no previous sync history. ' : '') .
        ($isModifiedSinceLastSync ? 'modified since last sync.' : '')));
      return $this->pushResult($pair, $result, $result::ELIGIBLE);
    }

    $result->setMessage('Sync is already up to date');
    return $this->pushResult($pair, $result, $result::NOT_NEEDED);
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
    $syncState->setSyncTime(date('Y-m-d H:i:s'));
    $syncState->setContactId(
      $localPersonAfter ? $localPersonAfter->getId() : NULL);
    $syncState->setRemotePersonId(
      $remotePersonAfter ? $remotePersonAfter->getId() : NULL);

    $syncState->setSyncOrigin(
      $pairAfter->isOriginLocal() ?
        PersonSyncState::ORIGIN_LOCAL : PersonSyncState::ORIGIN_REMOTE);

    if (isset($remotePersonBefore)) {
      $syncState->setRemotePreSyncModifiedTime(
        $this->modTimeAsTimestamp($remotePersonBefore));
    }

    if (isset($remotePersonAfter)) {
      $syncState->setRemotePostSyncModifiedTime(
        $this->modTimeAsTimestamp($remotePersonAfter));
    }

    if (isset($localPersonBefore)) {
      $syncState->setLocalPreSyncModifiedTime(
        $this->modTimeAsTimestamp($localPersonBefore));
    }

    if (isset($localPersonAfter)) {
      $syncState->setLocalPostSyncModifiedTime(
        $this->modTimeAsTimestamp($localPersonAfter));
    }

    $lastResult = $pairAfter->getLastResult();
    if ($lastResult) {
      if (is_a($lastResult, SyncResult::class)) {
        $code = $lastResult->getStatusCode();
      }
      else {
        $code = $lastResult->isError() ? SyncResult::ERROR : SyncResult::OTHER;
      }
      $syncState->setSyncStatus($code);
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

  /**
   * @param \Civi\Osdi\CrudObjectInterface $person
   */
  protected function modTimeAsTimestamp($person): ?string {
    if ($m = $person->modifiedDate->get()) {
      return $m;
    }
    return NULL;
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
    $pair->setVar('PersonSyncState', $syncState);

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

    if (isset($localObject) && isset($remoteObject)) {
      $pair->setLocalObject($localObject)->setRemoteObject($remoteObject);
      return TRUE;
    }

    return FALSE;
  }

}
