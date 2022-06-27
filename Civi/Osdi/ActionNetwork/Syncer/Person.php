<?php

namespace Civi\Osdi\ActionNetwork\Syncer;

use Civi\Osdi\ActionNetwork\Mapper\Person as PersonMapper;
use Civi\Osdi\ActionNetwork\Object\Person as RemotePerson;
use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\LocalObject\Person as LocalPerson;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\MatchResult;
use Civi\Osdi\PersonSyncerInterface;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\SaveResult;
use Civi\Osdi\SyncResult;
use CRM_Osdi_ExtensionUtil as E;
use Civi\Api4\OsdiPersonSyncState;
use Civi\Osdi\ActionNetwork\Matcher\OneToOneEmailOrFirstLastEmail;

class Person implements PersonSyncerInterface {

  private array $syncProfile;

  private RemoteSystemInterface $remoteSystem;

  /**
   * @var mixed
   */
  private $matcher;

  private PersonMapper $mapper;

  const inputTypeActionNetworkPersonObject = 'ActionNetwork:Person:Object';
  const inputTypeLocalContactId = 'Local:Contact:Id';
  const inputTypeLocalContactArray = 'Local:Contact:Array';
  const inputTypeLocalPersonObject = 'Local:Person:Object';

  /**
   * Syncer constructor.
   */
  public function __construct(RemoteSystemInterface $remoteSystem) {
    $this->remoteSystem = $remoteSystem;
  }

  private static function validateInputType(string $inputType) {
    if (!in_array($inputType, [
      self::inputTypeActionNetworkPersonObject,
      self::inputTypeLocalContactId,
      self::inputTypeLocalContactArray,
    ])) {
      throw new InvalidArgumentException(
        '%s is not a valid input type for %s',
        $inputType,
        __CLASS__
      );
    }
  }

  public function setRemoteSystem(RemoteSystemInterface $system): void {
    $this->remoteSystem = $system;
  }

  public function getRemoteSystem(): RemoteSystemInterface {
    return $this->remoteSystem;
  }

  public function getMapper() {
    if (empty($this->mapper)) {
      $mapperClass = $this->getSyncProfile()['mapper'];
      $this->mapper = new $mapperClass($this->getRemoteSystem());
    }
    return $this->mapper;
  }

  public function setMapper($mapper): void {
    $this->mapper = $mapper;
  }

  public function getMatcher(): OneToOneEmailOrFirstLastEmail {
    if (empty($this->matcher)) {
      $matcherClass = $this->getSyncProfile()['matcher'];
      $this->matcher = new $matcherClass($this);
    }
    return $this->matcher;
  }

  public function setMatcher($matcher): void {
    $this->matcher = $matcher;
  }

  public function getSyncProfile(): array {
    return $this->syncProfile;
  }

  public function setSyncProfile(array $syncProfile): void {
    $this->syncProfile = $syncProfile;
  }

  public function oneWaySync(string $inputType, $input): SyncResult {
    if (self::inputTypeActionNetworkPersonObject === $inputType) {
      return $this->oneWaySyncRemoteObject($input);
    }
    if (self::inputTypeLocalContactId === $inputType) {
      return $this->oneWaySyncContactById($input);
    }
    throw new InvalidArgumentException(
      '%s is not a valid input type for ' . __CLASS__ . '::'
      . __FUNCTION__,
      $inputType
    );
  }

  public function getOrCreateLocalRemotePair(string $inputType, $input): LocalRemotePair {
    if ($savedMatch = $this->getSavedMatch($inputType, $input)) {
      $message = 'fetched saved match';
      try {
        $localObject = (new LocalPerson($savedMatch['contact_id']))->load();
        $remoteObject = $this->getRemoteSystem()->fetchPersonById($savedMatch['remote_person_id']);
      }
      catch (InvalidArgumentException | EmptyResultException $e) {
        OsdiPersonSyncState::delete(FALSE)
          ->addWhere('id', '=', $savedMatch['id'])
          ->execute();
        $savedMatch = NULL;
      }
    }

    if (empty($savedMatch)) {
      $matchResult = $this->tryToFindMatch($inputType, $input);
      if ($matchResult->isError()) {
        $message = 'error finding match';
        $isError = TRUE;
      }

      elseif ($matchResult->gotMatch()) {
        $message = 'found new match with existing object';
        $inputTypeForSaveMatch = $inputType;
        $originObject = $matchResult->getOriginObject();
        $matchingObject = $matchResult->getMatch();
        if (MatchResult::ORIGIN_LOCAL === $matchResult->getOrigin()) {
          $inputTypeForSaveMatch = self::inputTypeLocalPersonObject;
          $localObject = $originObject;
          $remoteObject = $matchingObject;
        }
        else {
          $inputTypeForSaveMatch = self::inputTypeActionNetworkPersonObject;
          $localObject = $matchingObject;
          $remoteObject = $originObject;
        }
        $savedMatch = $this->saveMatch(
          $inputTypeForSaveMatch,
          $originObject,
          $matchingObject,
          NULL, NULL);
      }

      elseif (MatchResult::NO_MATCH == $matchResult->getStatus()) {
        $message = 'created matching object';
        $syncResult = $this->oneWaySync($inputType, $input);
        $localObject = $syncResult->getLocalObject();
        $remoteObject = $syncResult->getRemoteObject();
        if ($syncResult->isError()) {
          $message = 'error creating matching object';
          $isError = TRUE;
        }
      }
    }

    return new LocalRemotePair(
      $localObject ?? NULL,
      $remoteObject ?? NULL,
      $isError ?? FALSE,
      $message ?? '',
      $savedMatch ?? [],
      $matchResult ?? NULL,
      $syncResult ?? NULL);
  }

  public function getOrCreateMatchingObject(string $inputType, $input): SyncResult {
    if (self::inputTypeActionNetworkPersonObject === $inputType) {
      return $this->getOrCreateMatchingObjectForRemoteObject($input);
    }
    if (self::inputTypeLocalContactId === $inputType) {
      return $this->getOrCreateMatchingObjectForLocalId($input);
    }
    throw new InvalidArgumentException(
      '%s is not a valid input type for ' . __CLASS__ . '::'
      . __FUNCTION__,
      $inputType
    );
  }

  private function getOrCreateMatchingObjectForRemoteObject(\Civi\Osdi\ActionNetwork\Object\Person $remotePerson): SyncResult {
    if ($savedMatch = $this->getSavedMatchForRemotePerson($remotePerson)) {
      if ($localId = $savedMatch['contact_id']) {
        try {
          $localPerson = new LocalPerson($localId);
        }
        catch (InvalidArgumentException $e) {
          $localPerson = NULL;
          OsdiPersonSyncState::delete(FALSE)
            ->addWhere('id', '=', $savedMatch['id'])
            ->execute();
        }
        if ($localPerson) {
          return new SyncResult(
            $localPerson,
            $remotePerson,
            SyncResult::SUCCESS,
            'saved match', NULL,
            $savedMatch);
        }
      }
    }

    $match = $this->tryToFindMatch(self::inputTypeActionNetworkPersonObject, $remotePerson);

    if ($match->isError()) {
      return new SyncResult(
        NULL,
        $remotePerson,
        SyncResult::ERROR,
        'match error', NULL,
        $match
      );
    }

    if (MatchResult::NO_MATCH === $match->getStatus()) {
      return $this->oneWaySync(self::inputTypeActionNetworkPersonObject, $remotePerson);
    }

    $localPerson = $match->first();
    $this->saveMatch(
      self::inputTypeActionNetworkPersonObject,
      $remotePerson,
      $localPerson,
    );

    return new SyncResult(
      $localPerson,
      $remotePerson,
      NULL,
      'new match', NULL,
      $match);
  }

  private function getOrCreateMatchingObjectForLocalId(int $id): SyncResult {
    $localPerson = new LocalPerson($id);

    if ($savedMatch = $this->getSavedMatchForLocalContact($id)) {
      if ($remoteId = $savedMatch['remote_person_id']) {
        $remotePerson = $this->getRemoteSystem()->fetchPersonById($remoteId);
        return new SyncResult(
          $localPerson,
          $remotePerson,
          SyncResult::SUCCESS,
          'saved match', NULL,
          $savedMatch);
      }
    }

    $match = $this->tryToFindMatch(self::inputTypeLocalContactId, $id);

    if ($match->isError()) {
      return new SyncResult(
        $localPerson,
        NULL,
        SyncResult::ERROR,
        'match error', NULL,
        $match
      );
    }

    if (MatchResult::NO_MATCH === $match->getStatus()) {
      return $this->oneWaySync(self::inputTypeLocalContactId, $id);
    }

    $this->saveMatch(
      self::inputTypeLocalContactArray,
      $match->getOriginObject(),
      $match->first()
    );

    return new SyncResult(
      $match->getOriginObject(),
      $match->first(),
      NULL,
      'new match', NULL,
      $match);
  }

  /**
   * @param string $inputType inputType constant from this class
   * @param mixed $input thing to get a saved OsdiMatch for
   *
   * @return array OsdiMatch record, or empty array if none found
   */
  public function getSavedMatch(string $inputType, $input): array {
    self::validateInputType($inputType);

    $osdiMatchGetAction = OsdiPersonSyncState::get(FALSE)
      ->addWhere('sync_profile_id', '=', $this->syncProfile['id']);

    if (self::inputTypeActionNetworkPersonObject === $inputType) {
      /** @var \Civi\Osdi\ActionNetwork\Object\Person $input */
      $osdiMatchGetAction->addWhere('remote_person_id', '=', $input->getId());
    }
    if (self::inputTypeLocalContactId === $inputType) {
      /** @var positive-int $input */
      $osdiMatchGetAction->addWhere('contact_id', '=', $input);
    }
    if (self::inputTypeLocalContactArray === $inputType) {
      /** @var array $input */
      $osdiMatchGetAction->addWhere('contact_id', '=', $input['id']);
    }

    $osdiMatchGet = $osdiMatchGetAction->execute();

    if ($osdiMatchGet->count() > 1) {
      throw new \CRM_Core_Exception(E::ts(
        'There should only be one OsdiMatch per contact_id and '
        . 'sync_profile_id, %1 found',
        [1 => $osdiMatchGet->count()]
      ));
    }

    return $osdiMatchGet->first() ?? [];
  }

  public function getSavedMatchForLocalContact(int $contactId): array {
    return $this->getSavedMatch(self::inputTypeLocalContactId, $contactId);
  }

  public function getSavedMatchForRemotePerson(RemoteObjectInterface $remotePerson): array {
    return $this->getSavedMatch(self::inputTypeActionNetworkPersonObject, $remotePerson);
  }

  public function tryToFindMatch(string $inputType, $input) {
    if (self::inputTypeActionNetworkPersonObject == $inputType) {
      return $this->getMatcher()->tryToFindMatchForRemotePerson($input);
    }
    if (self::inputTypeLocalContactId == $inputType) {
      return $this->getMatcher()->tryToFindMatchForLocalContact(new LocalPerson($input));
    }
    self::validateInputType($inputType);
  }

  private function oneWaySyncContactById($id): SyncResult {
    $localPerson = new LocalPerson($id);
    $localPerson->loadOnce();

    $savedMatch = $this->getSavedMatchForLocalContact($id);
    if (empty($savedMatch)) {
      $matchingRemotePeople = $this->tryToFindMatch(self::inputTypeLocalContactId, $id);
      if (empty($matchingRemotePeople->getMatch())) {
        $actNetPerson = $this->getRemoteSystem()->makeOsdiObject('osdi:people');
      }
      else {
        $actNetPerson = $matchingRemotePeople->getRemoteObject();
      }
    }
    else {
      $actNetPerson = $this->getRemoteSystem()->makeOsdiObject('osdi:people');
      $actNetPerson->setId($savedMatch['remote_person_id']);
    }

    $changedActNetPerson = $this->getMapper()->mapLocalToRemote(
      $localPerson, $actNetPerson);
    $saveResult = $this->getRemoteSystem()->trySave($changedActNetPerson);
    /** @var \Civi\Osdi\ActionNetwork\Object\Person $remotePerson */
    $remotePerson = $saveResult->getReturnedObject();
    OsdiPersonSyncState::save(FALSE)
      ->setRecords([
        [
          'id' => $savedMatch['id'] ?? NULL,
          'contact_id' => $id,
          'remote_person_id' => $remotePerson ? $remotePerson->getId() : NULL,
          'sync_profile_id' => $this->syncProfile['id'],
          'sync_status' => $saveResult->getStatus(),
          'sync_origin' => OsdiPersonSyncState::syncOriginLocal,
          'sync_origin_modified_time' => $localPerson->modifiedDate->get(),
          'sync_target_modified_time' => $remotePerson ? $remotePerson->modifiedDate->get() : NULL,
        ],
      ])->execute();

    $logContext = [];
    if ($message = $saveResult->getMessage()) {
      $logContext[] = $message;
    }
    if ($saveResult->isError()) {
      $logContext[] = $saveResult->getContext();
    }
    \Civi::log()->debug(
      "OSDI sync attempt: contact $id: {$saveResult->getStatus()}",
      $logContext,
    );

    if ($saveResult->isError()) {
      return new SyncResult(
        $localPerson,
        $remotePerson,
        SyncResult::ERROR,
        $saveResult->getMessage(), NULL,
        $saveResult->getContext()
      );
    }

    return new SyncResult(
      $localPerson,
      $remotePerson,
      SyncResult::SUCCESS, NULL, NULL,
    );
  }

  private function oneWaySyncRemoteObject(\Civi\Osdi\ActionNetwork\Object\Person $remotePerson): SyncResult {
    $savedMatch = $this->getSavedMatchForRemotePerson($remotePerson);

    if (empty($savedMatch)) {
      $matchingLocalContacts = $this->tryToFindMatch(
        self::inputTypeActionNetworkPersonObject,
        $remotePerson
      );

      if (empty($matchingLocalContacts->getLocalObject())) {
        $localPerson = new LocalPerson();
      }
      else {
        $localPerson = $matchingLocalContacts->getLocalObject();
      }
    }
    else {
      $localPerson = new LocalPerson($savedMatch['contact_id']);
    }

    $localPerson = $this->getMapper()->mapRemoteToLocal(
      $remotePerson, $localPerson);
    try {
      $contactId = $localPerson->save()->getId();
      $status = SaveResult::SUCCESS;
      $exception = NULL;
    }
    catch (\API_Exception $exception) {
      $status = SaveResult::ERROR;
    }

    OsdiPersonSyncState::save(FALSE)
      ->setRecords([
        [
          'id' => $savedMatch['id'] ?? NULL,
          'contact_id' => $contactId ?? NULL,
          'remote_person_id' => $remotePerson->getId(),
          'sync_profile_id' => $this->syncProfile['id'],
          'sync_status' => $status,
          'sync_origin' => OsdiPersonSyncState::syncOriginRemote,
          'sync_origin_modified_time' => NULL,
          'sync_target_modified_time' => $localPerson->modifiedDate->get(),
        ],
      ])->execute();

    $logContext = [];
    if ($exception) {
      $logContext[] = $exception->getMessage();
    }
    \Civi::log()->debug(
      "OSDI sync attempt: contact $contactId: $status",
      $logContext,
    );
    if (SaveResult::ERROR === $status) {
      return new SyncResult(
        $localPerson,
        $remotePerson,
        $status, NULL, NULL
      );
    }

    return new SyncResult(
      new LocalPerson($contactId),
      $remotePerson,
      SyncResult::SUCCESS, NULL, NULL,
    );
  }

  private function saveMatch(string $inputType, $input, $matchingObject, string $syncStatus = NULL, int $matchId = NULL): array {
    /** @var \Civi\Osdi\LocalObject\Person $localPerson */
    /** @var \Civi\Osdi\ActionNetwork\Object\Person $remotePerson */
    if (self::inputTypeLocalContactArray == $inputType) {
      $localPerson = new LocalPerson($input['id']);
      $remotePerson = $matchingObject;
    }
    if (self::inputTypeActionNetworkPersonObject == $inputType) {
      $localPerson = $matchingObject;
      $remotePerson = $input;
    }
    if (self::inputTypeLocalPersonObject == $inputType) {
      $localPerson = $input;
      $remotePerson = $matchingObject;
    }
    if (empty($localPerson)) {
      throw new InvalidArgumentException($inputType);
    }

    $localPerson->loadOnce();

    return OsdiPersonSyncState::save(FALSE)
      ->setRecords([
        [
          'id' => $matchId,
          'contact_id' => $localPerson->getId(),
          'remote_person_id' => $remotePerson->getId(),
          'sync_profile_id' => $this->syncProfile['id'],
          'sync_status' => $syncStatus,
          'sync_origin_modified_time' => $remotePerson->modifiedDate->get(),
          'sync_target_modified_time' => $localPerson->modifiedDate->get(),
        ],
      ])->execute()->single();
  }

  public function syncFromRemoteIfNeeded(RemotePerson $remotePerson): SyncResult {
    // TODO: Implement syncFromRemoteIfNeeded() method.
  }

  public function getOrCreateLocalRemotePairFromRemote(RemotePerson $remotePerson): LocalRemotePair {
    // TODO: Implement getOrCreateLocalRemotePairFromRemote() method.
  }

}
