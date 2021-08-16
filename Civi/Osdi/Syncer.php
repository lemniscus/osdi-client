<?php

namespace Civi\Osdi;

use CRM_Osdi_ExtensionUtil as E;
use Civi\Api4\Email;
use Civi\Api4\OsdiMatch;
use Civi\Osdi\ActionNetwork\Matcher\OneToOneEmailOrFirstLastEmail;

class Syncer {

  /**
   * @var array
   */
  private $syncProfile;

  /**
   * @var \Civi\Osdi\RemoteSystemInterface
   */
  private $remoteSystem;

  /**
   * @var mixed
   */
  private $matcher;

  /**
   * Syncer constructor.
   */
  public function __construct(int $syncProfileId = NULL) {
    $this->setSyncProfile($syncProfileId);
  }

  public function setSyncProfile(int $syncProfileId = NULL): void {
    $getAction = \Civi\Api4\OsdiSyncProfile::get(FALSE);
    if ($syncProfileId) {
      $getAction = $getAction->addWhere('id', '=', $syncProfileId);
    }
    else {
      $getAction = $getAction->addWhere('is_default', '=', TRUE);
    }
    $this->syncProfile = $getAction->execute()->single();
  }

  public function getSyncProfile(): array {
    if (empty($this->syncProfile)) {
      $this->setSyncProfile();
    }
    return $this->syncProfile;
  }

  public function setRemoteSystem(RemoteSystemInterface $system = NULL): void {
    if (empty($system)) {
      $profile = new \CRM_OSDI_BAO_SyncProfile();
      $profile->copyValues($this->getSyncProfile());
      $systemClass = $profile->remote_system;
      $system = new $systemClass($profile);
    }
    $this->remoteSystem = $system;
  }

  public function getRemoteSystem(): RemoteSystemInterface {
    if (empty($this->remoteSystem)) {
      $this->setRemoteSystem();
    }
    return $this->remoteSystem;
  }

  private function getMatcher(): OneToOneEmailOrFirstLastEmail {
    if (empty($this->matcher)) {
      $matcherClass = $this->getSyncProfile()['matcher'];
      $this->matcher = new $matcherClass($this->getRemoteSystem());
    }
    return $this->matcher;
  }

  private function getMapper(): ActionNetwork\Mapper\Example {
    if (empty($this->mapper)) {
      $mapperClass = $this->getSyncProfile()['mapper'];
      $this->mapper = new $mapperClass($this->getRemoteSystem());
    }
    return $this->mapper;
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

  public function syncContact($id) {
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
        ],
      ])->execute();
    if ($saveResult->isError()) {
      return FALSE;
    }
  }

  public function syncRemotePerson(RemoteObjectInterface $person) {
    $contactId = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Bee')
      ->addValue('last_name', 'Bim')
      ->execute()->single()['id'];

    Email::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('email', 'bop@yum.com')
      ->execute();
  }

}
