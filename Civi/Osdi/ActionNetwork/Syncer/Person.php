<?php

namespace Civi\Osdi\ActionNetwork\Syncer;

use Civi\Osdi\ActionNetwork\Mapper\Example;
use Civi\Osdi\ActionNetwork\Object\Person as OsdiPersonObject;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\MatchResult;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\SaveResult;
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

  /**
   * Syncer constructor.
   */
  public function __construct(RemoteSystemInterface $remoteSystem) {
    $this->remoteSystem = $remoteSystem;
  }

  public function setRemoteSystem(RemoteSystemInterface $system): void {
    $this->remoteSystem = $system;
  }

  public function getRemoteSystem(): RemoteSystemInterface {
    return $this->remoteSystem;
  }

  private function getMapper() {
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
      $this->matcher = new $matcherClass($this->getRemoteSystem());
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

  public function oneWaySync(string $inputType, $input) {
    if ('ActionNetwork:Person:Object' === $inputType) {
      return $this->syncRemotePerson($input);
    }
    if ('Local:Contact:Id' === $inputType) {
      return $this->syncContact($input);
    }
    throw new InvalidArgumentException(
      '%s is not a valid input type for ' . __CLASS__ . '::'
      . __FUNCTION__,
      $inputType
    );
  }

  public function getSavedMatchForLocalContact(int $contactId): array {
    $osdiMatchGet = OsdiMatch::get(FALSE)
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('sync_profile_id', '=', $this->syncProfile['id'])
      ->execute();
    if ($osdiMatchGet->count() > 1) {
      throw new \CRM_Core_Exception(E::ts(
        'There should only be one OsdiMatch per contact_id and '
        . 'sync_profile_id, %1 found',
        [1 => $osdiMatchGet->count()]
      ));
    }
    return $osdiMatchGet->first() ?? [];
  }

  public function getSavedMatchForRemotePerson(RemoteObjectInterface $remotePerson): array {
    $osdiMatchGet = OsdiMatch::get(FALSE)
      ->addWhere('remote_person_id', '=', $remotePerson->getId())
      ->addWhere('sync_profile_id', '=', $this->syncProfile['id'])
      ->execute();
    if ($osdiMatchGet->count() > 1) {
      throw new \CRM_Core_Exception(E::ts(
        'There should only be one OsdiMatch per remote_person_id and '
        . 'sync_profile_id, %1 found',
        [1 => $osdiMatchGet->count()]
      ));
    }
    return $osdiMatchGet->first() ?? [];
  }

  public function findRemoteMatchForLocalContact(int $contactId): MatchResult {
    return $this->getMatcher()->findRemoteMatchForLocalContact($contactId);
  }

  public function findLocalMatchForRemotePerson($remotePerson): MatchResult {
    return $this->getMatcher()->findLocalMatchForRemotePerson($remotePerson);
  }

  private function syncContact($id) {
    $savedMatch = $this->getSavedMatchForLocalContact($id);
    if (empty($savedMatch)) {
      $matchingRemotePeople = $this->findRemoteMatchForLocalContact($id);
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
    $remotePerson = $saveResult->object();
    OsdiMatch::save(FALSE)
      ->setRecords([
        [
          'id' => $savedMatch['id'] ?? NULL,
          'contact_id' => $id,
          'remote_person_id' => $remotePerson ? $remotePerson->getId() : NULL,
          'sync_profile_id' => $this->syncProfile['id'],
          'sync_status' => $saveResult->status(),
          'sync_origin_modified_time' => NULL,
          'sync_target_modified_time' => $remotePerson ? $remotePerson
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
      return FALSE;
    }
    return TRUE;
  }
  private function syncRemotePerson(OsdiPersonObject $person) {
    $savedMatch = $this->getSavedMatchForRemotePerson($person);

    if (empty($savedMatch)) {
      $matchingLocalContacts = $this->findLocalMatchForRemotePerson($person);

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
    return TRUE;
  }

}
