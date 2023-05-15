<?php

namespace Civi\Osdi\ActionNetwork;

use Civi\Osdi\ActionNetwork\Object\Person as RemotePerson;
use Civi\Osdi\LocalObject\PersonBasic as LocalPerson;
use Civi\Osdi\RemoteObjectInterface;
use Civi\OsdiClient;

/**
 * This class does NOT implement TransactionalInterface, because it tests
 * behavior involving transactions. Therefore it must take care of its own
 * cleanup.
 *
 * Syncing a contact merge from Civi to AN does NOT just boil down to syncing a
 * deletion + syncing an update: the AN API doesn't allow true deletions, nor
 * does it allow assets (outreaches, donations, whatever) from one person to be
 * reassigned to/recreated on a different person.
 *
 * While the number of possible merge scenarios is enormous, as a result of many
 * variables (did the "duplicate" contact have a primary email address? Did the
 * surviving contact have one? Are they the same? Is there a PersonSyncState for
 * one contact, or the other, or both, or neither? Were the last syncs
 * successful? Did the merge involve changing the primary email address of the
 * surviving contact? etc etc), many of those scenarios are extremely unlikely
 * -- we hope. For example, it's possible the two contacts to be merged in Civi
 * have different email addresses and only one of them has a PersonSyncState
 * linking them to an AN person -- but that would only happen if the system had
 * broken at some point, so it should happen rarely or never.
 *
 * Therefore we try here to represent the scenarios that seem most likely to
 * occur during normal usage.
 *
 * @group headless
 */
class ContactMergeTest extends \PHPUnit\Framework\TestCase implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\HookInterface {

  private static array $tableMaxIds = [];

  private static \Civi\Osdi\ActionNetwork\RemoteSystem $system;

  private static bool $mergeEmailFromDupe = FALSE;

  private static bool $mergeNameFromDupe = FALSE;

  public static function hook_civicrm_merge($type, &$data, $mainId = NULL, $otherId = NULL, $tables = NULL) {
    if ($type !== 'batch') {
      return;
    }
    if (self::$mergeEmailFromDupe) {
      $data['fields_in_conflict']['move_location_email_0'] = TRUE;
      // '2' means 'overwrite': https://github.com/civicrm/civicrm-core/blob/master/CRM/Dedupe/MergeHandler.php#L186
      $data['migration_info']['location_blocks']['email'][0]['operation'] = 2;
      $data['migration_info']['location_blocks']['email'][0]['mainContactBlockId'] =
        $data['migration_info']['main_details']['location_blocks']['email'][0]['id'] ?? NULL;
    }
    if (self::$mergeNameFromDupe) {
      $data['fields_in_conflict']['move_first_name'] =
        $data['migration_info']['other_details']['first_name'];
    }
  }

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    $cleanupTables = [
      'civicrm_cache',
      'civicrm_contact',
      'civicrm_osdi_deletion',
      'civicrm_osdi_flag',
      'civicrm_osdi_person_sync_state',
      'civicrm_osdi_sync_profile',
      'civicrm_queue',
      'civicrm_queue_item',
      'civicrm_tag',
    ];
    foreach ($cleanupTables as $cleanupTable) {
      $max = \CRM_Core_DAO::singleValueQuery("SELECT MAX(id) FROM $cleanupTable");
      self::$tableMaxIds[$cleanupTable] = $max;
    };
  }

  protected function setUp(): void {
    self::$system = \OsdiClient\ActionNetwork\TestUtils::createRemoteSystem();
    self::$mergeEmailFromDupe = FALSE;
    self::$mergeNameFromDupe = FALSE;
    \Civi\Osdi\Queue::getQueue(TRUE);
    parent::setUp();
  }

  protected function tearDown(): void {
    foreach (self::$tableMaxIds as $table => $maxId) {
      $where = $maxId ? "WHERE id > $maxId" : "";
      \CRM_Core_DAO::singleValueQuery("DELETE FROM $table $where");
    }

    \Civi\Osdi\Queue::getQueue(TRUE);
    parent::tearDown();
  }

  /**
   * Some merges will be between Civi contacts with the same primary email. At
   * most one of them should have a successful sync history; there will only
   * be one AN person, since email is a unique identifier on AN people. Any
   * changes made to the surviving Civi contact as a result of the merge will
   * simply be mapped onto the AN person.
   */
  public function testMerge_BothContactsHaveSamePrimaryEmail() {
    $mainLocalPerson = $this->makeLocalPerson(1);
    $syncer = new \Civi\Osdi\ActionNetwork\SingleSyncer\Person\PersonBasic(self::$system);
    $mainPair = $syncer->matchAndSyncIfEligible($mainLocalPerson);
    self::assertFalse($mainPair->isError(),
      "Previous test contacts/emails may not have been deleted properly.\n"
      . print_r($mainPair->getResultStack()->toArray(), TRUE));

    $dupeLocalPerson = new LocalPerson();
    $dupeLocalPerson->emailEmail->set($mainLocalPerson->emailEmail->get());
    $dupeLocalPerson->firstName->set('Test Contact Merge - name from duplicate');
    $dupeLocalPerson->save();
    $dupePair = $syncer->matchAndSyncIfEligible($dupeLocalPerson);
    self::assertTrue($dupePair->isError(), print_r($dupePair->getResultStack()->toArray(), TRUE));

    $mainSyncState = $this->assertAndGetSuccessSyncState($mainLocalPerson);
    $dupeSyncState = $this->assertAndGetErrorSyncState($dupeLocalPerson);

    // Simulate a situation where the two records' last syncs are at least 1
    // second before the merge. In a production environment, we are exceedingly
    // unlikely to encounter any other situation.

    $mainSyncState->setLocalPostSyncModifiedTime(
      $mainSyncState->getLocalPostSyncModifiedTime() - 1);
    $mainSyncState->save();

    $dupeSyncState->setLocalPostSyncModifiedTime(
      $dupeSyncState->getLocalPostSyncModifiedTime() - 1);
    $dupeSyncState->save();

    // MERGE CONTACTS, AND RUN SYNC QUEUE
    self::$mergeNameFromDupe = TRUE;
    $this->mergeViaCiviApi3($dupeLocalPerson, $mainLocalPerson);
    $this->runQueueViaCiviApi3();
    $mergedLocalPerson = $this->getReloadedLocalPerson($mainLocalPerson);
    $discardedLocalPerson = $this->getReloadedLocalPerson($dupeLocalPerson);

    // AFTER MERGE
    $this->assertSameEmail($mainLocalPerson, $mergedLocalPerson);
    $this->assertSameName($dupeLocalPerson, $mergedLocalPerson);
    $this->assertDoesntHaveSyncState($discardedLocalPerson);
    // since the success sync state has a later timestamp and a remote id, it is kept
    $pssMerged = $this->assertAndGetSuccessSyncState($mergedLocalPerson);

    $remoteTwinOfMergedLocalPerson =
      RemotePerson::loadFromId(
        $pssMerged->getRemotePersonId(), self::$system);

    $this->assertIsMapped($mergedLocalPerson, $remoteTwinOfMergedLocalPerson);
    $this->assertNoDeletionRecord($remoteTwinOfMergedLocalPerson);
    $this->assertDoesntHaveFlag($mergedLocalPerson, $dupeLocalPerson);
  }

  /**
   * Exactly the same expectations for sync state, deletion and flagging as when
   * both contacts have the same primary email.
   */
  public function testMerge_OneContactHasNoPrimaryEmail() {
    $syncer = new \Civi\Osdi\ActionNetwork\SingleSyncer\Person\PersonBasic(self::$system);

    $mainLocalPerson = new LocalPerson();
    $mainLocalPerson->emailEmail->set(NULL);
    $mainLocalPerson->firstName->set('Test Contact Merge - name from main');
    $mainLocalPerson->save();
    $mainPair = $syncer->matchAndSyncIfEligible($mainLocalPerson);
    self::assertTrue($mainPair->isError(),
      print_r($mainPair->getResultStack()->toArray(), TRUE));

    $dupeLocalPerson = new LocalPerson();
    $dupeLocalPerson->emailEmail->set('contactMergeTest1@test.net');
    $dupeLocalPerson->firstName->set('Test Contact Merge - name from duplicate');
    $dupeLocalPerson->save();
    $dupePair = $syncer->matchAndSyncIfEligible($dupeLocalPerson);

    $mainPss = $this->assertAndGetErrorSyncState($mainLocalPerson);
    $dupePss = $this->assertAndGetSuccessSyncState($dupeLocalPerson);

    // simulate the sync histories being in the past
    $mainPss->setLocalPostSyncModifiedTime(time() - 1);
    $mainPss->save();
    $dupePss->setLocalPostSyncModifiedTime(time() - 1);
    $dupePss->save();

    // MERGE
    self::$mergeEmailFromDupe = TRUE;
    $this->mergeViaCiviApi3($dupeLocalPerson, $mainLocalPerson);
    $this->runQueueViaCiviApi3();
    $mergedLocalPerson = $this->getReloadedLocalPerson($mainLocalPerson);
    $discardedLocalPerson = $this->getReloadedLocalPerson($dupeLocalPerson);

    // AFTER MERGE
    $this->assertSameEmail($dupeLocalPerson, $mergedLocalPerson);
    $this->assertSameName($mainLocalPerson, $mergedLocalPerson);
    $this->assertDoesntHaveSyncState($discardedLocalPerson);
    // since the success sync state has a later timestamp and a remote id, it is kept
    $pssMerged = $this->assertAndGetSuccessSyncState($mergedLocalPerson);

    $remoteTwinOfMergedLocalPerson =
      RemotePerson::loadFromId(
        $pssMerged->getRemotePersonId(), self::$system);

    $this->assertIsMapped($mergedLocalPerson, $remoteTwinOfMergedLocalPerson);
    $this->assertNoDeletionRecord($remoteTwinOfMergedLocalPerson);
    $this->assertDoesntHaveFlag($mergedLocalPerson, $dupeLocalPerson);
  }

  /**
   * If the two Civi contacts have different primary emails, it's almost certain
   * some manual intervention will be needed. If, specifically, the two different
   * primary emails both have counterparts on AN, then a human will need to
   * complete the merge on AN. This is true no matter whether the records are
   * synced or not, and no matter which email is kept on the merged record. Even
   * if we pseudo-deleted the AN-side twin of the "duplicate"/discarded Civi
   * contact, it may have assets that we can't move to the survivor record.
   */
  public function testMerge_ContactsHadDifferentEmails_SurvivorEmailKept_BothHadSyncHistory() {
    $syncer = new \Civi\Osdi\ActionNetwork\SingleSyncer\Person\PersonBasic(self::$system);

    $mainLocalPerson = $this->makeLocalPerson(1);
    $mainPair = $syncer->matchAndSyncIfEligible($mainLocalPerson);
    $remoteTwinOfMainLocalPerson = $mainPair->getRemoteObject();

    $dupeLocalPerson = $this->makeLocalPerson(2);
    $dupePair = $syncer->matchAndSyncIfEligible($dupeLocalPerson);
    $remoteTwinOfDupeLocalPerson = $dupePair->getRemoteObject();

    $this->assertHasSyncState($mainLocalPerson);
    $this->assertHasSyncState($dupeLocalPerson);

    // MERGE
    self::$mergeEmailFromDupe = FALSE;
    self::$mergeNameFromDupe = TRUE;
    $this->mergeViaCiviApi3($dupeLocalPerson, $mainLocalPerson);
    $this->runQueueViaCiviApi3();
    $mergedLocalPerson = $this->getReloadedLocalPerson($mainLocalPerson);
    $discardedLocalPerson = $this->getReloadedLocalPerson($dupeLocalPerson);

    // AFTER MERGE
    $this->assertSameEmail($mainLocalPerson, $mergedLocalPerson);
    $this->assertSameName($dupeLocalPerson, $mergedLocalPerson);
    $this->assertDoesntHaveSyncState($discardedLocalPerson);

    $this->assertIsUnchanged($remoteTwinOfMainLocalPerson);
    $this->assertIsUnchanged($remoteTwinOfDupeLocalPerson);
    $this->assertNoDeletionRecord($remoteTwinOfMainLocalPerson);
    $this->assertNoDeletionRecord($remoteTwinOfDupeLocalPerson);
    $this->assertLocalPeopleHaveFlags($mergedLocalPerson, $discardedLocalPerson);
    $this->assertRemotePeopleHaveFlags($remoteTwinOfMainLocalPerson, $remoteTwinOfDupeLocalPerson);
  }

  private function assertAndGetErrorSyncState(
    LocalPerson $localPerson
  ): \Civi\Osdi\PersonSyncState {
    $syncState = \Civi\Osdi\PersonSyncState::getForLocalPerson(
      $localPerson, OsdiClient::container()->getSyncProfileId());
    self::assertNotEmpty($syncState->getId());
    self::assertTrue($syncState->isError(), print_r($syncState->toArray(), TRUE));
    return $syncState;
  }

  private function assertAndGetSuccessSyncState(
    LocalPerson $localPerson
  ): \Civi\Osdi\PersonSyncState {
    $syncState = \Civi\Osdi\PersonSyncState::getForLocalPerson(
      $localPerson, OsdiClient::container()->getSyncProfileId());
    self::assertNotEmpty($syncState->getId());
    self::assertFalse($syncState->isError(), print_r($syncState->toArray(), TRUE));
    return $syncState;
  }

  private function assertDoesntHaveFlag(...$localPeople): void {
    $contactIds = array_map(function ($p) {return $p->getID();}, $localPeople);
    $flags = \Civi\Api4\OsdiFlag::get(FALSE)
      ->addWhere('contact_id', 'IN', $contactIds)
      ->execute();

    self::assertCount(0, $flags);
  }

  private function assertDoesntHaveSyncState(LocalPerson $p): void {
    $syncState = \Civi\Osdi\PersonSyncState::getForLocalPerson(
      $p, OsdiClient::container()->getSyncProfileId());
    self::assertEmpty($syncState->getId());
  }

  private function assertLocalPeopleHaveFlags(...$localPeople): void {
    $contactIds = array_map(function ($p) {return $p->getID();}, $localPeople);
    $flags = \Civi\Api4\OsdiFlag::get(FALSE)
      ->addWhere('contact_id', 'IN', $contactIds)
      ->execute();

    self::assertCount(count($localPeople), $flags);
  }

  private function assertRemotePeopleHaveFlags(...$remotePeople): void {
    $personIds = array_map(function ($p) {return $p->getID();}, $remotePeople);
    $flags = \Civi\Api4\OsdiFlag::get(FALSE)
      ->addWhere('remote_object_id', 'IN', $personIds)
      ->execute();

    self::assertCount(count($remotePeople), $flags);
  }

  private function assertHasSyncState(LocalPerson $p): void {
    $syncState = \Civi\Osdi\PersonSyncState::getForLocalPerson(
      $p, OsdiClient::container()->getSyncProfileId());
    self::assertNotEmpty($syncState->getId());
  }

  private function assertIsMapped(
    LocalPerson $localPerson,
    RemoteObjectInterface $remotePerson
  ): void {
    self::assertEquals($localPerson->emailEmail->get(),
      $remotePerson->emailAddress->get());
    self::assertEquals($localPerson->firstName->get(),
      $remotePerson->givenName->get());
  }

  private function assertIsUnchanged($oldRemotePerson): void {
    $newRemotePerson = $this->getReloadedRemotePerson($oldRemotePerson);
    self::assertEquals($oldRemotePerson->emailAddress->get(),
      $newRemotePerson->emailAddress->get());
    self::assertEquals($oldRemotePerson->givenName->get(),
      $newRemotePerson->givenName->get());
  }

  private function assertNoDeletionRecord($remotePerson) {
    $deletion = \Civi\Api4\OsdiDeletion::get(FALSE)
      ->addWhere('remote_object_id', '=', $remotePerson->getId())
      ->addWhere('sync_profile_id', 'IS NULL')
      ->execute();
    self::assertCount(0, $deletion);
  }

  private function assertSameEmail(
    LocalPerson $person1,
    LocalPerson $person2
  ): void {
    self::assertEquals(
      $person1->emailEmail->get(),
      $person2->emailEmail->get(),
    );
  }

  private function assertSameName(
    LocalPerson $person1,
    LocalPerson $person2
  ): void {
    self::assertEquals(
      $person1->firstName->get(),
      $person2->firstName->get(),
    );
  }

  private function getReloadedLocalPerson(LocalPerson $localPerson): LocalPerson {
    $reloadedLocalPerson = new LocalPerson($localPerson->getId());
    return $reloadedLocalPerson->loadOnce();
  }

  private function getReloadedRemotePerson(RemoteObjectInterface $remotePerson): RemoteObjectInterface {
    return RemotePerson::loadFromId($remotePerson->getId(), self::$system);
  }

  private function makeLocalPerson(string $index): LocalPerson {
    $localPerson = new LocalPerson();
    $localPerson->emailEmail->set("contactMergeTest$index@test.net");
    $localPerson->firstName->set("Test Contact Merge $index");
    $localPerson->save();
    return $localPerson;
  }

  private function mergeViaCiviApi3(
    LocalPerson $dupeLocalPerson,
    LocalPerson $mainLocalPerson
  ): void {
    include_once 'api/v3/Contact.php';
    // we call this method directly so we can access thrown errors
    \civicrm_api3_contact_merge([
      'to_remove_id' => $dupeLocalPerson->getId(),
      'to_keep_id' => $mainLocalPerson->getId(),
      'mode' => 'aggressive',
    ]);
  }

  private function runQueueViaCiviApi3(): void {
    $queueJobResult = civicrm_api3('Job', 'osdiclientprocessqueue',
      ['debug' => 1, 'api_token' => ACTION_NETWORK_TEST_API_TOKEN]);

    self::assertEquals(0, $queueJobResult['is_error']);
  }

}
