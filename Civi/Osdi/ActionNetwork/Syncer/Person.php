<?php

namespace Civi\Osdi\ActionNetwork\Syncer;

use Civi\Api4\Contact;
use Civi\Osdi\ActionNetwork\Mapper\Example;
use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\MatchResult;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\SaveResult;
use Civi\Osdi\SyncResult;
use CRM_Osdi_ExtensionUtil as E;
use Civi\Api4\OsdiMatch;
use Civi\Osdi\ActionNetwork\Matcher\OneToOneEmailOrFirstLastEmail;

class Person {

  private array $syncProfile;

  private RemoteSystemInterface $remoteSystem;

  /**
   * @var mixed
   */
  private $matcher;

  private Example $mapper;

  const inputTypeActionNetworkPersonObject = 'ActionNetwork:Person:Object';
  const inputTypeLocalContactId = 'Local:Contact:Id';
  const inputTypeLocalContactArray = 'Local:Contact:Array';

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
        $localObject = $this->getMapper()->getSingleCiviContactById($savedMatch['contact_id']);
        $remoteObject = $this->getRemoteSystem()->fetchPersonById($savedMatch['remote_person_id']);
      }
      catch (InvalidArgumentException | EmptyResultException $e) {
        OsdiMatch::delete(FALSE)
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

      elseif (1 === $matchResult->count()) {
        $message = 'found new match with existing object';
        $inputTypeForSaveMatch = $inputType;
        $originObject = $matchResult->getOriginObject();
        $matchingObject = $matchResult->first();
        if (is_array($originObject)) {
          $inputTypeForSaveMatch = self::inputTypeLocalContactArray;
          $localObject = $originObject;
          $remoteObject = $matchingObject;
        }
        else {
          $localObject = $matchingObject;
          $remoteObject = $originObject;
        }
        $savedMatch = $this->saveMatch(
          $inputTypeForSaveMatch,
          $originObject,
          $matchingObject,
          NULL, NULL);
      }

      elseif (MatchResult::NO_MATCH == $matchResult->status()) {
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

  private function getOrCreateMatchingObjectForRemoteObject(\Civi\Osdi\ActionNetwork\Object\Person $person): SyncResult {
    if ($savedMatch = $this->getSavedMatchForRemotePerson($person)) {
      if ($localId = $savedMatch['contact_id']) {
        try {
          $localContactArray = $this->mapper->getSingleCiviContactById($localId);
        }
        catch (InvalidArgumentException $e) {
          $localContactArray = NULL;
          OsdiMatch::delete(FALSE)
            ->addWhere('id', '=', $savedMatch['id'])
            ->execute();
        }
        if ($localContactArray) {
          return new SyncResult(
            $localContactArray,
            $person,
            SyncResult::SUCCESS,
            'saved match',
            $savedMatch);
        }
      }
    }

    $match = $this->tryToFindMatch(self::inputTypeActionNetworkPersonObject, $person);

    if ($match->isError()) {
      return new SyncResult(
        NULL,
        $person,
        SyncResult::ERROR,
        'match error',
        $match
      );
    }

    if (MatchResult::NO_MATCH === $match->status()) {
      return $this->oneWaySync(self::inputTypeActionNetworkPersonObject, $person);
    }

    $localContactArray = $match->first();
    $this->saveMatch(
      self::inputTypeActionNetworkPersonObject,
      $person,
      $localContactArray,
    );

    return new SyncResult(
      $localContactArray,
      $person,
      NULL,
      'new match',
      $match);
  }

  private function getOrCreateMatchingObjectForLocalId(int $id): SyncResult {
    if ($savedMatch = $this->getSavedMatchForLocalContact($id)) {
      if ($remoteId = $savedMatch['remote_person_id']) {
        $remotePerson = $this->getRemoteSystem()->fetchPersonById($remoteId);
        return new SyncResult([], $remotePerson,
          SyncResult::SUCCESS,
          'saved match',
        $savedMatch);
      }
    }

    $match = $this->tryToFindMatch(self::inputTypeLocalContactId, $id);

    if ($match->isError()) {
      return new SyncResult(
        $id,
        NULL,
        SyncResult::ERROR,
        'match error',
        $match
      );
    }

    if (MatchResult::NO_MATCH === $match->status()) {
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
      'new match',
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

    $osdiMatchGetAction = OsdiMatch::get(FALSE)
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
      return $this->getMatcher()->tryToFindMatchForLocalContact($input);
    }
    self::validateInputType($inputType);
  }

  private function oneWaySyncContactById($id): SyncResult {
    $savedMatch = $this->getSavedMatchForLocalContact($id);
    if (empty($savedMatch)) {
      $matchingRemotePeople = $this->tryToFindMatch(self::inputTypeLocalContactId, $id);
      if (0 === $matchingRemotePeople->count()) {
        $person = $this->getRemoteSystem()->makeOsdiObject('osdi:people');
      }
      elseif (1 === $matchingRemotePeople->count()) {
        $person = $matchingRemotePeople->first();
      }
    }
    else {
      $person = $this->getRemoteSystem()->makeOsdiObject('osdi:people');
      $person->setId($savedMatch['remote_person_id']);
    }
    $changedPerson = $this->getMapper()->mapContactOntoRemotePerson($id, $person);
    $saveResult = $this->getRemoteSystem()->trySave($changedPerson);
    /** @var \Civi\Osdi\ActionNetwork\Object\Person $remoteObject */
    $remoteObject = $saveResult->object();
    OsdiMatch::save(FALSE)
      ->setRecords([
        [
          'id' => $savedMatch['id'] ?? NULL,
          'contact_id' => $id,
          'remote_person_id' => $remoteObject ? $remoteObject->getId() : NULL,
          'sync_profile_id' => $this->syncProfile['id'],
          'sync_status' => $saveResult->status(),
          'sync_origin' => OsdiMatch::syncOriginLocal,
          'sync_origin_modified_time' => NULL,
          'sync_target_modified_time' => $remoteObject ? $remoteObject
            ->get('modified_date') : NULL,
        ],
      ])->execute();

    $logContext = [];
    if ($message = $saveResult->message()) {
      $logContext[] = $message;
    }
    if ($saveResult->isError()) {
      $logContext[] = $saveResult->context();
    }
    \Civi::log()->debug(
      "OSDI sync attempt: contact $id: {$saveResult->status()}",
      $logContext,
    );

    if ($saveResult->isError()) {
      return new SyncResult(
        Contact::get(FALSE)->addWhere('id', '=', $id)->execute()->single(),
        $remoteObject,
        SyncResult::ERROR,
        $saveResult->message(),
        $saveResult->context()
      );
    }

    return new SyncResult(
      Contact::get(FALSE)->addWhere('id', '=', $id)->execute()->single(),
      $remoteObject,
      SyncResult::SUCCESS,
    );
  }

  private function oneWaySyncRemoteObject(\Civi\Osdi\ActionNetwork\Object\Person $person): SyncResult {
    $savedMatch = $this->getSavedMatchForRemotePerson($person);

    if (empty($savedMatch)) {
      $matchingLocalContacts = $this->tryToFindMatch(
        self::inputTypeActionNetworkPersonObject,
        $person
      );

      if (0 === $matchingLocalContacts->count()) {
        $contactId = NULL;
      }
      elseif (1 === $matchingLocalContacts->count()) {
        $contactId = $matchingLocalContacts->first()['id'];
      }
    }
    else {
      $contactId = $savedMatch['contact_id'];
    }

    $contactSaveAction = $this->getMapper()->mapRemotePersonOntoContact($person, $contactId);
    try {
      $saveResult = $contactSaveAction->execute();
      $contact = $saveResult->single();
      $contactId = $contact['id'];
      $status = SaveResult::SUCCESS;
      $exception = NULL;
    }
    catch (\API_Exception $exception) {
      $status = SaveResult::ERROR;
    }

    OsdiMatch::save(FALSE)
      ->setRecords([
        [
          'id' => $savedMatch['id'] ?? NULL,
          'contact_id' => $contactId ?? NULL,
          'remote_person_id' => $person->getId(),
          'sync_profile_id' => $this->syncProfile['id'],
          'sync_status' => $status,
          'sync_origin' => OsdiMatch::syncOriginRemote,
          'sync_origin_modified_time' => NULL,
          'sync_target_modified_time' => $contact ? $contact['modified_date'] : NULL,
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
      return FALSE;
    }

    return new SyncResult(
      $this->mapper->getSingleCiviContactById($contactId),
      $person,
      SyncResult::SUCCESS,
    );
  }

  private function saveMatch(string $inputType, $input, $matchingObject, string $syncStatus = NULL, int $matchId = NULL): array {
    if (self::inputTypeLocalContactArray == $inputType) {
      $localContactArray = $input;
      $remotePerson = $matchingObject;
    }
    if (self::inputTypeActionNetworkPersonObject == $inputType) {
      $localContactArray = $matchingObject;
      $remotePerson = $input;
    }
    if (empty($localContactArray)) {
      throw new InvalidArgumentException($inputType);
    }

    return OsdiMatch::save(FALSE)
      ->setRecords([
        [
          'id' => $matchId,
          'contact_id' => $localContactArray['id'],
          'remote_person_id' => $remotePerson->getId(),
          'sync_profile_id' => $this->syncProfile['id'],
          'sync_status' => $syncStatus,
          'sync_origin_modified_time' => $remotePerson->get('modified_date'),
          'sync_target_modified_time' => $localContactArray['modified_date'],
        ],
      ])->execute()->single();
  }

}
