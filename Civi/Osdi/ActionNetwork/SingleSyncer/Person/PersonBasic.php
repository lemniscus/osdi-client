<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer\Person;

use Civi\Osdi\LocalObjectInterface;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Logger;
use Civi\Osdi\MapperInterface;
use Civi\Osdi\MatcherInterface;
use Civi\Osdi\PersonSyncState;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\Result\FetchOldOrFindNewMatch as OldOrNewMatchResult;
use Civi\Osdi\Result\MapAndWrite as MapAndWriteResult;
use Civi\Osdi\Result\MatchResult as MatchResult;
use Civi\Osdi\Result\Sync;
use Civi\Osdi\SingleSyncerInterface;
use Civi\Osdi\Util;

class PersonBasic implements SingleSyncerInterface {

  use PersonLocalRemotePairTrait;

  protected ?MapperInterface $mapper = NULL;

  protected ?MatcherInterface $matcher = NULL;

  protected RemoteSystemInterface $remoteSystem;

  protected array $syncProfile = [];

  private ?int $syncProfileId = NULL;

  public function __construct(RemoteSystemInterface $remoteSystem) {
    $this->remoteSystem = $remoteSystem;
  }

  public function fetchOldOrFindAndSaveNewMatch(LocalRemotePair $pair): OldOrNewMatchResult {
    // TODO: Implement fetchOldOrFindNewAndSaveMatch() method.
  }

  public function fetchOldOrFindNewMatch(LocalRemotePair $pair): OldOrNewMatchResult {
    $result = new OldOrNewMatchResult();

    $syncState = $pair->isOriginLocal()
      ? PersonSyncState::getForLocalPerson($pair->getLocalObject(), $this->syncProfileId)
      : PersonSyncState::getForRemotePerson($pair->getRemoteObject(), $this->syncProfileId);

    if ($this->fillLocalRemotePairFromSyncState($pair, $syncState)) {
      $result->setStatusCode($result::FETCHED_SAVED_MATCH);
      $pair->getResultStack()->push($result);
      return $result;
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
      $pair->setTargetObject($matchResult->getMatch());
      $result->setStatusCode($result::FOUND_NEW_MATCH);
    }

    $pair->getResultStack()->push($result);
    return $result;
  }

  public function makeLocalObject($id = NULL): LocalObjectInterface {
    // TODO: Implement makeLocalObject() method.
  }

  public function makeRemoteObject($id = NULL): RemoteObjectInterface {
    // TODO: Implement makeRemoteObject() method.
  }

  public function matchAndSyncIfEligible($originObject): LocalRemotePair {
    // TODO: Implement matchAndSyncIfEligible() method.
  }

  public function oneWayMapAndWrite(LocalRemotePair $pair): MapAndWriteResult {
    // TODO: Implement oneWayMapAndWrite() method.
  }

  public function oneWayWriteFromRemote(LocalRemotePair $localRemotePair): Sync {
    $remotePerson = $this->typeCheckRemotePerson(
      $localRemotePair->getRemoteObject());

    $syncState = $localRemotePair->getPersonSyncState() ??
      PersonSyncState::getForRemotePerson($remotePerson, $this->syncProfileId);
    $this->fillLocalRemotePairFromSyncState($localRemotePair, $syncState);

    if (empty($localPerson = $localRemotePair->getLocalObject())) {
      $localPersonClass = $this->getLocalObjectClass();
      $localPerson = new $localPersonClass();
      if ($contactId = $syncState->getContactId()) {
        $localPerson->setId($contactId);
        $localPerson->loadOnce();
      }
    }
    else {
      $localPerson->loadOnce();
    }
    $localPerson = $this->typeCheckLocalPerson($localPerson);

    $remoteModifiedTime = $this->modTimeAsUnixTimestamp($remotePerson);
    $localPreSyncModifiedTime = $this->modTimeAsUnixTimestamp($localPerson);

    $localPerson = $this->getMapper()->mapRemoteToLocal(
      $remotePerson, $localPerson);

    try {
      if ($localPerson->isAltered()) {
        $localPerson->save();
        $statusMessage = empty($localPreSyncModifiedTime)
          ? 'created new Civi contact'
          : 'altered existing Civi contact';
        $localPostSyncModifiedTime = $this->modTimeAsUnixTimestamp($localPerson);
      }
      else {
        $statusMessage = 'no changes made to Civi contact';
        $localPostSyncModifiedTime = $localPreSyncModifiedTime;
      }
      $statusCode = Sync::SUCCESS;
    }
    catch (\API_Exception $exception) {
      $statusCode = Sync::ERROR;
      $statusMessage = 'exception when saving local contact';
      Logger::logError("OSDI Client sync error: $statusMessage", [
        'remote person id' => $remotePerson->getId(),
        'exception' => $exception,
      ]);
    }

    $syncState->setSyncProfileId($this->syncProfileId);
    $syncState->setSyncOrigin(PersonSyncState::ORIGIN_REMOTE);
    $syncState->setSyncTime(time());
    $syncState->setContactId($localPerson->getId());
    $syncState->setRemotePersonId($remotePerson->getId());
    $syncState->setRemotePreSyncModifiedTime($remoteModifiedTime);
    $syncState->setRemotePostSyncModifiedTime($remoteModifiedTime);
    $syncState->setLocalPreSyncModifiedTime($localPreSyncModifiedTime);
    $syncState->setLocalPostSyncModifiedTime($localPostSyncModifiedTime ?? NULL);
    $syncState->setSyncStatus($statusCode);
    $syncState->save();

    return new Sync(
      $localPerson,
      $remotePerson,
      $statusCode,
      $statusMessage,
      $syncState
    );
  }

  public function oneWayWriteFromLocal(LocalRemotePair $localRemotePair): Sync {
    $localPerson = $this->typeCheckLocalPerson(
      $localRemotePair->getLocalObject()->loadOnce());

    $syncState = $localRemotePair->getPersonSyncState() ??
      PersonSyncState::getForLocalPerson($localPerson, $this->syncProfileId);
    $this->fillLocalRemotePairFromSyncState($localRemotePair, $syncState);

    if (empty($remotePerson = $localRemotePair->getRemoteObject())) {
      $remotePersonClass = $this->getRemoteObjectClass();
      $remotePerson = new $remotePersonClass($this->getRemoteSystem());
      if ($remotePersonId = $syncState->getRemotePersonId()) {
        $remotePerson->setId($remotePersonId);
        $remotePerson->loadOnce();
      }
    }
    else {
      $remotePerson->loadOnce();
    }
    $remotePerson = $this->typeCheckRemotePerson($remotePerson);

    $localModifiedTime = $this->modTimeAsUnixTimestamp($localPerson);
    $remotePreSyncModifiedTime = $this->modTimeAsUnixTimestamp($remotePerson);

    $remotePerson = $this->getMapper()->mapLocalToRemote(
      $localPerson, $remotePerson);

    if ($remotePerson->isAltered()) {
      $saveResult = $remotePerson->trySave();
      $remotePerson = $saveResult->getReturnedObject();
      if (empty($remotePreSyncModifiedTime)) {
        $statusMessage = 'created new AN person';
      }
      else {
        $statusMessage = 'altered existing AN person';
        $context = $saveResult->getContext();
      }
      $remotePostSyncModifiedTime = $this->modTimeAsUnixTimestamp($remotePerson);
    }
    else {
      $statusMessage = 'no changes made to Action Network person';
      $remotePostSyncModifiedTime = $remotePreSyncModifiedTime;
    }

    $statusCode = Sync::SUCCESS;

    if (isset($saveResult) && $saveResult->isError()) {
      $statusCode = Sync::ERROR;
      $statusMessage = 'problem when saving Action Network person: '
        . $saveResult->getMessage();

      $context = $saveResult->getContext();
      $context = is_array($context) ? $context : [$context];
      $context['contact id'] = $localPerson->getId();
      Logger::logError("OSDI Client sync error: $statusMessage", $context);
    }

    $syncState->setSyncProfileId($this->syncProfileId);
    $syncState->setSyncOrigin(PersonSyncState::ORIGIN_LOCAL);
    $syncState->setSyncTime(time());
    $syncState->setContactId($localPerson->getId());
    $syncState->setRemotePersonId($remotePerson->getId());
    $syncState->setRemotePreSyncModifiedTime($remotePreSyncModifiedTime);
    $syncState->setRemotePostSyncModifiedTime($remotePostSyncModifiedTime);
    $syncState->setLocalPreSyncModifiedTime($localModifiedTime);
    $syncState->setLocalPostSyncModifiedTime($localModifiedTime);
    $syncState->setSyncStatus($statusCode);
    $syncState->save();

    return new Sync(
      $localPerson,
      $remotePerson,
      $statusCode,
      $statusMessage,
      $syncState,
      $context ?? NULL
    );
  }

  public function syncFromRemoteIfNeeded(RemoteObjectInterface $remoteObject): Sync {
    $pair = $this->getOrCreateLocalRemotePairFromRemote($remoteObject);
    if ('created matching object' === $pair->getMessage()) {
      return $pair->getSyncResult();
    }

    if ('error finding match' === $pair->getMessage()) {
      $matchResult = $pair->getResultStack()->getLastOfType(MatchResult::class);
      return new Sync(
        NULL,
        $remoteObject,
        Sync::ERROR,
        'Match error: ' . $matchResult->getMessage(),
        NULL,
        $matchResult->getContext()
      );
    }

    if ($pair->isError()) {
      return new Sync(
        NULL,
        $remoteObject,
        Sync::ERROR,
        'Error: ' . $pair->getMessage(),
        NULL,
        $pair
      );
    }

    if (empty($syncState = $pair->getPersonSyncState())) {
      $syncState = PersonSyncState::getForRemotePerson($remoteObject, $this->syncProfileId);
    }

    $noPreviousSync = empty($postSyncModTime = $syncState->getRemotePostSyncModifiedTime());
    $modifiedSinceLastSync = $postSyncModTime < $this->modTimeAsUnixTimestamp($remoteObject);
    if ($noPreviousSync || $modifiedSinceLastSync) {
      return $this->oneWayWriteFromRemote($pair);
    }

    return new Sync(
      NULL,
      $remoteObject,
      Sync::NO_SYNC_NEEDED,
      'Sync is already up to date',
      $syncState
    );
  }

  public function syncFromLocalIfNeeded(LocalObjectInterface $localObject): Sync {
    $localPersonClass = $this->getLocalObjectClass();
    $remotePersonClass = $this->getRemoteObjectClass();

    \Civi\Osdi\Util::assertClass($localObject, $localPersonClass);

    $pair = $this->getOrCreateLocalRemotePairFromLocal($localObject);

    if ('person has no qualifying email or phone' == $pair->getMessage()) {
      return $pair->getSyncResult();
    }

    if ('created matching object' == $pair->getMessage()) {
      return $pair->getSyncResult();
    }

    if (empty($syncState = $pair->getPersonSyncState())) {
      $syncState = PersonSyncState::getForLocalPerson($localObject, $this->syncProfileId);
    }
    $pair->setPersonSyncState($syncState);

    $noPreviousSync = empty($postSyncModTime = $syncState->getLocalPostSyncModifiedTime());
    $modifiedSinceLastSync = $postSyncModTime < $this->modTimeAsUnixTimestamp($localObject->loadOnce());
    if ($noPreviousSync || $modifiedSinceLastSync) {
      return $this->oneWayWriteFromLocal($pair);
    }

    return new Sync(
      $localObject,
      NULL,
      Sync::NO_SYNC_NEEDED,
      'Sync is already up to date',
      $syncState
    );
  }

  protected function getOrCreateLocalRemotePairFromLocal(LocalObjectInterface $localPerson): LocalRemotePair {
    $pair = (new LocalRemotePair())
      ->setLocalObject($localPerson)
      ->setLocalClass($this->getLocalObjectClass())
      ->setRemoteClass($this->getRemoteObjectClass());

    $syncState = PersonSyncState::getForLocalPerson($localPerson, $this->syncProfileId);
    if ($this->fillLocalRemotePairFromSyncState($pair, $syncState)) {
      return $pair;
    }

    $pair->setMatchResult($matchResult = $this->getMatcher()
      ->tryToFindMatchForLocalObject($pair));

    if ($matchResult->isError()) {
      return $pair->setIsError(TRUE)->setMessage('error finding match');
    }

    elseif (MatchResult::NO_MATCH == $matchResult->getStatusCode()) {
      if (!$this->newRemoteShouldBeCreatedForLocal($pair)) {
        return $pair;
      }
      $syncResult = $this->oneWayWriteFromLocal($pair);
      return $this->fillLocalRemotePairFromSyncResult($syncResult, $pair);
    }

    else {
      return $this->fillLocalRemotePairFromNewfoundMatch($matchResult, $pair);
    }
  }

  protected function getOrCreateLocalRemotePairFromRemote(RemoteObjectInterface $remotePerson): LocalRemotePair {
    $pair = (new LocalRemotePair())
      ->setRemoteObject($remotePerson)
      ->setLocalClass($this->getLocalObjectClass())
      ->setRemoteClass($this->getRemoteObjectClass());

    $syncState = PersonSyncState::getForRemotePerson($remotePerson, $this->syncProfileId);
    if ($this->fillLocalRemotePairFromSyncState($pair, $syncState)) {
      return $pair;
    }

    $pair->setMatchResult($matchResult = $this->getMatcher()
      ->tryToFindMatchForRemoteObject($pair));

    if ($matchResult->isError()) {
      return $pair->setIsError(TRUE)->setMessage('error finding match');
    }

    elseif (MatchResult::NO_MATCH == $matchResult->getStatusCode()) {
      $syncResult = $this->oneWayWriteFromRemote($pair);
      return $this->fillLocalRemotePairFromSyncResult($syncResult, $pair);
    }

    else {
      return $this->fillLocalRemotePairFromNewfoundMatch($matchResult, $pair);
    }
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
   * @param \Civi\Osdi\RemoteObjectInterface|\Civi\Osdi\LocalObjectInterface $person
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

  protected function typeCheckLocalPerson(LocalObjectInterface $object): \Civi\Osdi\LocalObject\PersonBasic {
    Util::assertClass($object, \Civi\Osdi\LocalObject\PersonBasic::class);
    /** @var \Civi\Osdi\LocalObject\PersonBasic $object */
    return $object;
  }

  protected function typeCheckRemotePerson(RemoteObjectInterface $object): \Civi\Osdi\ActionNetwork\Object\Person {
    Util::assertClass($object, \Civi\Osdi\ActionNetwork\Object\Person::class);
    /** @var \Civi\Osdi\ActionNetwork\Object\Person $object */
    return $object;
  }

  protected function getLocalObjectClass(): string {
    return \Civi\Osdi\LocalObject\PersonBasic::class;
  }

  protected function getRemoteObjectClass() {
    return \Civi\Osdi\ActionNetwork\Object\Person::class;
  }

  public function setMapper(?MapperInterface $mapper): self {
    $this->mapper = $mapper;
    return $this;
  }

  public function getMapper(): MapperInterface {
    return $this->mapper;
  }

  public function setMatcher(?MatcherInterface $matcher): self {
    $this->matcher = $matcher;
    return $this;
  }

  public function getMatcher(): MatcherInterface {
    return $this->matcher;
  }

  public function setRemoteSystem(RemoteSystemInterface $remoteSystem): self {
    $this->remoteSystem = $remoteSystem;
    return $this;
  }

  public function getRemoteSystem(): RemoteSystemInterface {
    return $this->remoteSystem;
  }

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

}
