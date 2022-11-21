<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer;

use Civi\Osdi\ActionNetwork\Object\Person as ANPerson;
use Civi\Osdi\Factory;
use Civi\Osdi\Result\FetchOldOrFindNewMatch;
use Civi\Osdi\Result\MapAndWrite;
use Civi\Osdi\Result\Sync;
use Civi\Osdi\Result\SyncEligibility;
use CRM_OSDI_Fixture_PersonMatching as PersonMatchFixture;

abstract class PersonTestAbstract extends \PHPUnit\Framework\TestCase {

  protected static \Civi\Osdi\SingleSyncerInterface $syncer;
  protected static \Civi\Osdi\RemoteSystemInterface $remoteSystem;
  protected static array $createdRemotePeople = [];

  protected function tearDown(): void {
    while ($person = array_pop(self::$createdRemotePeople)) {
      $person->delete();
    }
  }

  public function testMatchAndSyncIfEligible_WriteNewTwin_FromLocal() {
    $syncer = self::$syncer;
    $localPerson = Factory::make('LocalObject', 'Person');
    $localPerson->firstName->set('testMatchAndSyncIfEligible_FromLocal');
    $emailAddress = 'testMatchAndSyncIfEligible_FromLocal_' . time() . '@civicrm.org';
    $localPerson->emailEmail->set($emailAddress);
    $localPerson->save();

    $remotePeopleWithTheEmail = self::$remoteSystem->find(
      'osdi:people',
      [['email', 'eq', $emailAddress]]);

    if ($remotePeopleWithTheEmail->rawCurrentCount()) {
      $remotePeopleWithTheEmail->rawFirst()->delete();
      $remotePeopleWithTheEmail = self::$remoteSystem->find(
        'osdi:people',
        [['email', 'eq', $emailAddress]]);
    }

    self::assertEquals(0, $remotePeopleWithTheEmail->filteredCurrentCount());

    // FIRST SYNC
    $pair = $syncer->matchAndSyncIfEligible($localPerson);
    $resultStack = $pair->getResultStack();
    $fetchFindMatchResult = $resultStack->getLastOfType(FetchOldOrFindNewMatch::class);
    $matchResult = $resultStack->getLastOfType(\Civi\Osdi\Result\MatchResult::class);
    $mapAndWriteResult = $resultStack->getLastOfType(MapAndWrite::class);
    $syncEligibleResult = $resultStack->getLastOfType(SyncEligibility::class);
    $syncResult = $resultStack->getLastOfType(Sync::class);
    $savedMatch = $syncResult->getState();

    self::assertEquals(FetchOldOrFindNewMatch::NO_MATCH_FOUND, $fetchFindMatchResult->getStatusCode());
    self::assertEquals('No match by email, first name and last name', $matchResult->getMessage());
    self::assertEquals(MapAndWrite::WROTE_NEW, $mapAndWriteResult->getStatusCode());
    self::assertEquals(SyncEligibility::ELIGIBLE, $syncEligibleResult->getStatusCode());
    self::assertEquals(Sync::SUCCESS, $syncResult->getStatusCode());
    self::assertEquals(\Civi\Osdi\PersonSyncState::class, get_class($savedMatch));
    self::assertNotNull($pair->getRemoteObject()->getId());

    self::assertEquals('testMatchAndSyncIfEligible_FromLocal',
      $pair->getRemoteObject()->givenName->get());

    // SECOND SYNC
    $pair = $syncer->matchAndSyncIfEligible($localPerson);
    $resultStack = $pair->getResultStack();
    $fetchFindMatchResult = $resultStack->getLastOfType(FetchOldOrFindNewMatch::class);
    $mapAndWriteResult = $resultStack->getLastOfType(MapAndWrite::class);
    $syncEligibleResult = $resultStack->getLastOfType(SyncEligibility::class);
    $syncResult = $resultStack->getLastOfType(Sync::class);
    $savedMatch = $syncResult->getState();

    self::assertEquals(FetchOldOrFindNewMatch::FETCHED_SAVED_MATCH, $fetchFindMatchResult->getStatusCode());
    self::assertEquals(SyncEligibility::INELIGIBLE, $syncEligibleResult->getStatusCode());
    self::assertNull($mapAndWriteResult);
    self::assertEquals(Sync::NO_SYNC_NEEDED, $syncResult->getStatusCode());
    self::assertEquals(\Civi\Osdi\PersonSyncState::class, get_class($savedMatch));
    self::assertNotNull($pair->getRemoteObject()->getId());

    self::assertEquals('testMatchAndSyncIfEligible_FromLocal',
      $pair->getLocalObject()->firstName->get());

    // THIRD SYNC
    // Spoof a situation in which the remote person has been modified since the last sync
    $savedMatch->setLocalPostSyncModifiedTime(
      $savedMatch->getLocalPostSyncModifiedTime() - 1);
    $savedMatch->save();

    $savedMatch->save();
    $pair = $syncer->matchAndSyncIfEligible($localPerson);
    $resultStack = $pair->getResultStack();
    $fetchFindMatchResult = $resultStack->getLastOfType(FetchOldOrFindNewMatch::class);
    $mapAndWriteResult = $resultStack->getLastOfType(MapAndWrite::class);
    $syncEligibleResult = $resultStack->getLastOfType(SyncEligibility::class);
    $syncResult = $resultStack->getLastOfType(Sync::class);
    $savedMatch = $syncResult->getState();

    self::assertEquals(FetchOldOrFindNewMatch::FETCHED_SAVED_MATCH, $fetchFindMatchResult->getStatusCode());
    self::assertEquals(MapAndWrite::NO_CHANGES_TO_WRITE, $mapAndWriteResult->getStatusCode());
    self::assertEquals(SyncEligibility::ELIGIBLE, $syncEligibleResult->getStatusCode());
    self::assertEquals(Sync::SUCCESS, $syncResult->getStatusCode());
    self::assertEquals(\Civi\Osdi\PersonSyncState::class, get_class($savedMatch));
    self::assertNotNull($pair->getRemoteObject()->getId());

    self::assertEquals('testMatchAndSyncIfEligible_FromLocal',
      $pair->getRemoteObject()->givenName->get());

    // FOURTH SYNC
    // Spoof a situation in which the remote person has been modified since the last sync
    $savedMatch->setLocalPostSyncModifiedTime(
      $savedMatch->getLocalPostSyncModifiedTime() - 1);
    $savedMatch->save();

    $localPerson->firstName->set('testMatchAndSyncIfEligible_FromLocal (new name)');
    $localPerson->save();

    $pair = $syncer->matchAndSyncIfEligible($localPerson);
    $resultStack = $pair->getResultStack();
    $fetchFindMatchResult = $resultStack->getLastOfType(FetchOldOrFindNewMatch::class);
    $mapAndWriteResult = $resultStack->getLastOfType(MapAndWrite::class);
    $syncEligibleResult = $resultStack->getLastOfType(SyncEligibility::class);
    $syncResult = $resultStack->getLastOfType(Sync::class);
    $savedMatch = $syncResult->getState();

    self::assertEquals(FetchOldOrFindNewMatch::FETCHED_SAVED_MATCH, $fetchFindMatchResult->getStatusCode());
    self::assertEquals(SyncEligibility::ELIGIBLE, $syncEligibleResult->getStatusCode(), $syncEligibleResult->getMessage());
    self::assertEquals(MapAndWrite::WROTE_CHANGES, $mapAndWriteResult->getStatusCode());
    self::assertEquals(Sync::SUCCESS, $syncResult->getStatusCode());
    self::assertEquals(\Civi\Osdi\PersonSyncState::class, get_class($savedMatch));
    self::assertNotNull($pair->getRemoteObject()->getId());

    self::assertEquals('testMatchAndSyncIfEligible_FromLocal (new name)',
      $pair->getRemoteObject()->givenName->get());
  }

  public function testMatchAndSyncIfEligible_WriteNewTwin_FromRemote() {
    $syncer = self::$syncer;
    $remotePerson = new ANPerson(self::$remoteSystem);
    $remotePerson->givenName->set('testMatchAndSyncIfEligible_FromRemote');
    $remotePerson->emailAddress->set('testMatchAndSyncIfEligible_FromRemote@test.net');
    $remotePerson->save();

    // FIRST SYNC
    $pair = $syncer->matchAndSyncIfEligible($remotePerson);
    $resultStack = $pair->getResultStack();
    $fetchFindMatchResult = $resultStack->getLastOfType(FetchOldOrFindNewMatch::class);
    $matchResult = $resultStack->getLastOfType(\Civi\Osdi\Result\MatchResult::class);
    $mapAndWriteResult = $resultStack->getLastOfType(MapAndWrite::class);
    $syncEligibleResult = $resultStack->getLastOfType(SyncEligibility::class);
    $syncResult = $resultStack->getLastOfType(Sync::class);
    $savedMatch = $syncResult->getState();

    self::assertEquals(FetchOldOrFindNewMatch::NO_MATCH_FOUND, $fetchFindMatchResult->getStatusCode());
    self::assertEquals('No match by email', $matchResult->getMessage());
    self::assertEquals(MapAndWrite::WROTE_NEW, $mapAndWriteResult->getStatusCode());
    self::assertEquals(SyncEligibility::ELIGIBLE, $syncEligibleResult->getStatusCode());
    self::assertEquals(Sync::SUCCESS, $syncResult->getStatusCode());
    self::assertEquals(\Civi\Osdi\PersonSyncState::class, get_class($savedMatch));
    self::assertNotNull($pair->getLocalObject()->getId());

    self::assertEquals('testMatchAndSyncIfEligible_FromRemote',
      $pair->getLocalObject()->firstName->get());

    // SECOND SYNC
    $pair = $syncer->matchAndSyncIfEligible($remotePerson);
    $resultStack = $pair->getResultStack();
    $fetchFindMatchResult = $resultStack->getLastOfType(FetchOldOrFindNewMatch::class);
    $mapAndWriteResult = $resultStack->getLastOfType(MapAndWrite::class);
    $syncEligibleResult = $resultStack->getLastOfType(SyncEligibility::class);
    $syncResult = $resultStack->getLastOfType(Sync::class);
    $savedMatch = $syncResult->getState();

    self::assertEquals(FetchOldOrFindNewMatch::FETCHED_SAVED_MATCH, $fetchFindMatchResult->getStatusCode());
    self::assertEquals(SyncEligibility::INELIGIBLE, $syncEligibleResult->getStatusCode());
    self::assertNull($mapAndWriteResult);
    self::assertEquals(Sync::NO_SYNC_NEEDED, $syncResult->getStatusCode());
    self::assertEquals(\Civi\Osdi\PersonSyncState::class, get_class($savedMatch));
    self::assertNotNull($pair->getLocalObject()->getId());

    self::assertEquals('testMatchAndSyncIfEligible_FromRemote',
      $pair->getLocalObject()->firstName->get());

    // THIRD SYNC

    // Spoof a situation in which the remote person has been modified since the last sync
    $savedMatch->setRemotePostSyncModifiedTime(
      $savedMatch->getRemotePostSyncModifiedTime() - 1);
    $savedMatch->save();

    $pair = $syncer->matchAndSyncIfEligible($remotePerson);
    $resultStack = $pair->getResultStack();
    $fetchFindMatchResult = $resultStack->getLastOfType(FetchOldOrFindNewMatch::class);
    $mapAndWriteResult = $resultStack->getLastOfType(MapAndWrite::class);
    $syncEligibleResult = $resultStack->getLastOfType(SyncEligibility::class);
    $syncResult = $resultStack->getLastOfType(Sync::class);
    $savedMatch = $syncResult->getState();

    self::assertEquals(FetchOldOrFindNewMatch::FETCHED_SAVED_MATCH, $fetchFindMatchResult->getStatusCode());
    self::assertEquals(SyncEligibility::ELIGIBLE, $syncEligibleResult->getStatusCode());
    self::assertEquals(MapAndWrite::NO_CHANGES_TO_WRITE, $mapAndWriteResult->getStatusCode());
    self::assertEquals(Sync::SUCCESS, $syncResult->getStatusCode());
    self::assertEquals(\Civi\Osdi\PersonSyncState::class, get_class($savedMatch));
    self::assertNotNull($pair->getLocalObject()->getId());

    self::assertEquals('testMatchAndSyncIfEligible_FromRemote',
      $pair->getLocalObject()->firstName->get());

    // FOURTH SYNC
    // Spoof a situation in which the remote person has been modified since the last sync
    $savedMatch->setRemotePostSyncModifiedTime(
      $savedMatch->getRemotePostSyncModifiedTime() - 1);
    $savedMatch->save();

    $remotePerson->givenName->set('testMatchAndSyncIfEligible_FromRemote (new name)');
    $remotePerson->save();

    $pair = $syncer->matchAndSyncIfEligible($remotePerson);
    $resultStack = $pair->getResultStack();
    $fetchFindMatchResult = $resultStack->getLastOfType(FetchOldOrFindNewMatch::class);
    $mapAndWriteResult = $resultStack->getLastOfType(MapAndWrite::class);
    $syncEligibleResult = $resultStack->getLastOfType(SyncEligibility::class);
    $syncResult = $resultStack->getLastOfType(Sync::class);
    $savedMatch = $syncResult->getState();

    self::assertEquals(FetchOldOrFindNewMatch::FETCHED_SAVED_MATCH, $fetchFindMatchResult->getStatusCode());
    self::assertEquals(SyncEligibility::ELIGIBLE, $syncEligibleResult->getStatusCode(), $syncEligibleResult->getMessage());
    self::assertEquals(MapAndWrite::WROTE_CHANGES, $mapAndWriteResult->getStatusCode());
    self::assertEquals(Sync::SUCCESS, $syncResult->getStatusCode());
    self::assertEquals(\Civi\Osdi\PersonSyncState::class, get_class($savedMatch));
    self::assertNotNull($pair->getLocalObject()->getId());

    self::assertEquals('testMatchAndSyncIfEligible_FromRemote (new name)',
      $pair->getLocalObject()->firstName->get());
  }

  public function dataProviderDeletedPersonDoesNotGetRecreated() {
    return [
      [TRUE],
      [FALSE],
    ];
  }

  /**
   * @dataProvider dataProviderDeletedPersonDoesNotGetRecreated
   */
  public function testDeletedPersonDoesNotGetRecreated($useSyncProfile) {
    $syncerClass = get_class(self::$syncer);
    $syncer = new $syncerClass(self::$remoteSystem);
    if ($useSyncProfile) {
      $syncer->setSyncProfile(self::$syncer->getSyncProfile());
    }

    $localPerson = Factory::make('LocalObject', 'Person');
    $localPerson->firstName->set('Cheese');
    $localPerson->emailEmail->set('bitz@bop.com');
    $localPerson->save();
    $civiToAnPair = $syncer->matchAndSyncIfEligible($localPerson);
    $remotePerson = $civiToAnPair->getRemoteObject();

    self::assertFalse($civiToAnPair->isError());
    self::assertNotNull($remotePerson->getId());
    self::assertEquals('bitz@bop.com', $remotePerson->emailAddress->get());

    $syncer->syncDeletion($civiToAnPair);

    $anToCiviPair = $syncer->matchAndSyncIfEligible($remotePerson);

    $resultStack = $anToCiviPair->getResultStack();
    $fetchFindMatchResult = $resultStack->getLastOfType(FetchOldOrFindNewMatch::class);
    $mapAndWriteResult = $resultStack->getLastOfType(MapAndWrite::class);
    $syncEligibleResult = $resultStack->getLastOfType(SyncEligibility::class);
    $syncResult = $resultStack->getLastOfType(Sync::class);
    $savedMatch = $syncResult->getState();

    self::assertEquals(FetchOldOrFindNewMatch::FETCHED_SAVED_MATCH, $fetchFindMatchResult->getStatusCode());
    self::assertEquals(SyncEligibility::INELIGIBLE, $syncEligibleResult->getStatusCode());
    self::assertNull($mapAndWriteResult);
    self::assertEquals(Sync::NO_SYNC_NEEDED, $syncResult->getStatusCode());
    self::assertEquals(\Civi\Osdi\PersonSyncState::class, get_class($savedMatch));
    self::assertEquals(
      $civiToAnPair->getLocalObject()->getId(), $anToCiviPair->getLocalObject()->getId());
  }

  public function testRemoteSystemIsSettable() {
    $syncer = Factory::make('SingleSyncer', 'Person', self::$remoteSystem);
    $originalSystem = $syncer->getRemoteSystem();
    $defaultSystemAEP = $originalSystem->getEntryPoint();

    $differentSystemAEP = $defaultSystemAEP . 'with suffix';
    $client = new \Jsor\HalClient\HalClient(
      $differentSystemAEP, new \CRM_OSDI_FixtureHttpClient()
    );
    $differentSystem = new \Civi\Osdi\ActionNetwork\RemoteSystem(
      NULL,
      $client);
    $syncer->setRemoteSystem($differentSystem);

    self::assertEquals(
      $differentSystemAEP,
      rawurldecode($syncer->getRemoteSystem()->getEntryPoint())
    );
  }

  public function testSyncFromRemoteIfNeeded_NewPerson() {
    // SETUP

    $firstName = 'Bee';
    $lastName = 'Bim';
    $emailAddress = 'bop@yum.com';

    $person = self::$createdRemotePeople[] = new ANPerson(self::$remoteSystem);
    $person->givenName->set($firstName);
    $person->familyName->set($lastName);
    $person->emailAddress->set($emailAddress);
    $person->save();

    \Civi\Api4\Email::delete(FALSE)
      ->addWhere('email', '=', $emailAddress)
      ->execute();

    // PRE-ASSERTS

    $existingMatchHistory = \Civi\Api4\OsdiPersonSyncState::get(FALSE)
      ->addWhere('remote_person_id', '=', $person->getId())
      ->execute();

    self::assertCount(0, $existingMatchHistory);

    // TEST PROPER

    $pair = self::$syncer->matchAndSyncIfEligible($person);
    $result = $pair->getResultStack()->getLastOfType(Sync::class);

    self::assertEquals(\Civi\Osdi\Result\Sync::SUCCESS, $result->getStatusCode());

    $localContactsWithTheEmail = \Civi\Api4\Contact::get()
      ->addJoin('Email AS email', 'LEFT')
      ->addWhere('email.email', '=', $emailAddress)
      ->execute();

    self::assertEquals(1, $localContactsWithTheEmail->count());

    $contact = $localContactsWithTheEmail->single();

    self::assertEquals($pair->getLocalObject()->getId(), $contact['id']);
    self::assertEquals($firstName, $contact['first_name']);
    self::assertEquals($lastName, $contact['last_name']);
  }

  public function testSyncFromLocalIfNeeded_NewPerson() {
    // SETUP

    $firstName = 'Bee';
    $lastName = 'Bim';
    $emailAddress = 'bop@yum.com';
    $contact = PersonMatchFixture::civiApi4CreateContact(
      $firstName,
      $lastName,
      $emailAddress)
      ->single();

    // PRE-ASSERTS

    $existingMatchHistory = \Civi\Api4\OsdiPersonSyncState::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->execute();

    self::assertCount(0, $existingMatchHistory);

    $remotePeopleWithTheEmail = self::$remoteSystem->find(
      'osdi:people',
      [['email', 'eq', $emailAddress]]);

    if ($remotePeopleWithTheEmail->rawCurrentCount()) {
      $remotePeopleWithTheEmail->rawFirst()->delete();
      $remotePeopleWithTheEmail = self::$remoteSystem->find(
        'osdi:people',
        [['email', 'eq', $emailAddress]]);
    }

    self::assertEquals(0, $remotePeopleWithTheEmail->filteredCurrentCount());

    // TEST PROPER

    $localPerson = Factory::make('LocalObject', 'Person', $contact['id']);
    $pair = self::$syncer->matchAndSyncIfEligible($localPerson);
    /** @var \Civi\Osdi\Result\Sync $result */
    $result = $pair->getResultStack()->getLastOfType(Sync::class);

    self::assertEquals(\Civi\Osdi\Result\Sync::SUCCESS, $result->getStatusCode());

    $remotePeopleWithTheEmail = self::$remoteSystem->find(
      'osdi:people',
      [['email', 'eq', $emailAddress]]);
    $remotePersonWithTheEmail = $remotePeopleWithTheEmail->filteredFirst();
    self::$createdRemotePeople[] = $remotePersonWithTheEmail;

    self::assertEquals(1, $remotePeopleWithTheEmail->filteredCurrentCount());

    self::assertEquals($pair->getRemoteObject()->getId(),
      $remotePersonWithTheEmail->getId());

    self::assertEquals(
      $firstName,
      $remotePersonWithTheEmail->givenName->get());

    self::assertEquals(
      $lastName,
      $remotePersonWithTheEmail->familyName->get());
  }

  public function testSyncFromRemoteIfNeeded_ChangedName() {
    // SETUP

    /** @var \Civi\Osdi\ActionNetwork\Object\Person $originalRemotePerson */
    $originalRemotePerson = PersonMatchFixture::makeNewOsdiPersonWithFirstLastEmail();
    self::$createdRemotePeople[] = $originalRemotePerson->save();
    $pair = self::$syncer->matchAndSyncIfEligible($originalRemotePerson);

    self::assertNotTrue($pair->isError());

    $originalLocalPerson = $pair->getLocalObject();
    $contactId = $originalLocalPerson->getId();
    $originalContact = PersonMatchFixture::civiApi4GetSingleContactById($contactId);

    // TEST PROPER

    $pair = self::$syncer->matchAndSyncIfEligible($originalRemotePerson);
    $syncResult = $pair->getResultStack()->getLastOfType(Sync::class);

    self::assertEquals(\Civi\Osdi\Result\Sync::NO_SYNC_NEEDED,
      $syncResult->getStatusCode());

    $syncState = $syncResult->getState();
    $syncState->setRemotePostSyncModifiedTime(
      $syncState->getRemotePostSyncModifiedTime() - 1);
    $syncState->save();

    $pair = self::$syncer->matchAndSyncIfEligible($originalRemotePerson);
    $syncResult = $pair->getResultStack()->getLastOfType(Sync::class);

    self::assertEquals(\Civi\Osdi\Result\Sync::SUCCESS,
      $syncResult->getStatusCode());

    $syncState->setRemotePostSyncModifiedTime(
      $syncState->getRemotePostSyncModifiedTime() - 1);
    $syncState->save();

    $changedRemotePerson = clone $originalRemotePerson;
    $changedFirstName = $originalRemotePerson->givenName->get() . ' plus this';
    $changedRemotePerson->givenName->set($changedFirstName);
    $changedRemotePerson->save();

    self::assertNotEquals(
      $changedFirstName,
      $originalContact['first_name']);

    $pair = self::$syncer->matchAndSyncIfEligible($changedRemotePerson);
    $syncResult = $pair->getResultStack()->getLastOfType(Sync::class);

    self::assertEquals(\Civi\Osdi\Result\Sync::SUCCESS,
      $syncResult->getStatusCode());

    $syncedContact = PersonMatchFixture::civiApi4GetSingleContactById($contactId);

    self::assertEquals(
      $changedFirstName,
      $syncedContact['first_name']);

    self::assertEquals(
      $originalRemotePerson->familyName->get(),
      $syncedContact['last_name']);
  }

  public function testChangedNameIncomingDoesNotDuplicateAddressesEtc() {
    self::markTestIncomplete('Todo');
  }

  public function testSyncFromLocalIfNeeded_ChangedName() {
    // SETUP

    /** @var \Civi\Osdi\ActionNetwork\Object\Person $originalRemotePerson */
    [$originalRemotePerson, $contactId] =
      PersonMatchFixture::setUpExactlyOneMatchByEmailAndName(self::$remoteSystem);
    self::$createdRemotePeople[] = $originalRemotePerson;

    $originalFirstName = $originalRemotePerson->givenName->get();

    // TEST PROPER

    $changedFirstName = $originalFirstName . ' plus this';

    \Civi\Api4\Contact::update(FALSE)
      ->addWhere('id', '=', $contactId)
      ->addValue('first_name', $changedFirstName)
      ->execute();

    $localPerson = Factory::make('LocalObject', 'Person', $contactId);
    $pair = self::$syncer->matchAndSyncIfEligible($localPerson);

    $remotePeopleWithTheEmail = self::$remoteSystem->find(
      'osdi:people',
      [['email', 'eq', $originalRemotePerson->emailAddress->get()]]);
    self::$createdRemotePeople[] = $remotePeopleWithTheEmail->filteredFirst();

    self::assertEquals(
      $pair->getRemoteObject()->getId(),
      $remotePeopleWithTheEmail->filteredFirst()->getId());

    self::assertEquals(
      $changedFirstName,
      $remotePeopleWithTheEmail->filteredFirst()->givenName->get());

    self::assertEquals(
      $originalRemotePerson->familyName->get(),
      $remotePeopleWithTheEmail->filteredFirst()->familyName->get());
  }

  public function testSyncFromRemoteIfNeeded_AddedAddress() {
    // SETUP

    /** @var \Civi\Osdi\ActionNetwork\Object\Person $remotePerson */
    [$remotePerson, $contactId] =
      PersonMatchFixture::setUpExactlyOneMatchByEmailAndName(self::$remoteSystem);

    self::$createdRemotePeople[] = $remotePerson;

    $originalContact = PersonMatchFixture::civiApi4GetSingleContactById($contactId);

    // TEST PROPER

    $newStreet = '119 Difficult Rd.';
    $remotePerson->postalStreet->set($newStreet);
    $remotePerson->postalCode->set('37030');
    $remotePerson->save();

    self::assertEquals('37030', $remotePerson->postalCode->get());
    self::assertNotEquals($newStreet,
      $originalContact['address.street_address'] ?? NULL);

    $syncResult = self::$syncer->matchAndSyncIfEligible($remotePerson);

    $syncedContact = PersonMatchFixture::civiApi4GetSingleContactById($contactId);

    self::assertNotTrue($syncResult->isError());

    self::assertEquals(
      $newStreet,
      $syncedContact['address.street_address']);
  }

  public function testSyncFromLocalIfNeeded_AddedAddress_Success() {
    // SETUP

    [$originalRemotePerson, $contactId] =
      PersonMatchFixture::setUpExactlyOneMatchByEmailAndName(self::$remoteSystem);
    self::$createdRemotePeople[] = $originalRemotePerson;

    $newStreet = '123 Test St.';

    // TEST PROPER

    $reFetchedRemotePerson =
      ANPerson::loadFromId($originalRemotePerson->getId(), self::$remoteSystem);

    self::assertNotEquals($newStreet, $reFetchedRemotePerson->postalStreet->get());

    \Civi\Api4\Address::create()
      ->addValue('contact_id', $contactId)
      ->addValue('location_type_id', 1)
      ->addValue('is_primary', TRUE)
      ->addValue('street_address', $newStreet)
      ->addValue('postal_code', '65542')
      ->execute();

    $localPerson = Factory::make('LocalObject', 'Person', $contactId);
    self::$syncer->matchAndSyncIfEligible($localPerson);

    $reFetchedRemotePerson =
      ANPerson::loadFromId($originalRemotePerson->getId(), self::$remoteSystem);

    self::assertEquals($newStreet, $reFetchedRemotePerson->postalStreet->get());
  }


  /**
   * As of November 14, 2022, Action Network seems to be filling in missing
   * postal codes based
   */
  public function testSyncFromLocalIfNeeded_AddedAddress_FailsDueToNoZip() {
    // SETUP

    [$originalRemotePerson, $contactId] =
      PersonMatchFixture::setUpExactlyOneMatchByEmailAndName(self::$remoteSystem);
    self::$createdRemotePeople[] = $originalRemotePerson;

    /** @var \Civi\Osdi\ActionNetwork\Object\Person $originalRemotePerson */
    $originalRemotePerson->postalStreet->set('1600 Pennsylvania Ave., NW');
    $originalRemotePerson->postalLocality->set('Washington');
    $originalRemotePerson->postalRegion->set('DC');
    $originalRemotePerson->postalCode->set('20500');
    $originalRemotePerson->save();

    self::assertNotEquals('GA', $originalRemotePerson->postalRegion->get());

    // TEST PROPER

    \Civi\Api4\Address::create()
      ->addValue('contact_id', $contactId)
      ->addValue('location_type_id', 1)
      ->addValue('is_primary', TRUE)
      ->addValue('city', 'Albany')
      ->addValue('state_province_id:name', 'Georgia')
      ->addValue('postal_code', '')
      ->execute();

    $localPerson = Factory::make('LocalObject', 'Person', $contactId);
    self::$syncer->matchAndSyncIfEligible($localPerson);

    $reFetchedRemotePerson =
      ANPerson::loadFromId($originalRemotePerson->getId(), self::$remoteSystem);

    self::assertNotEquals('GA',
      $reFetchedRemotePerson->postalRegion->get());
  }

  public function testSyncFromLocalIfNeeded_ChangedEmail_Success() {
    $firstNameOne = 'Bee';
    $lastName = 'Bim';
    $emailOne = 'bop@yum.com';
    $localPerson = Factory::make('LocalObject', 'Person');
    $localPerson->firstName->set($firstNameOne);
    $localPerson->lastName->set($lastName);
    $localPerson->emailEmail->set($emailOne);
    $localPerson->save();
    $contactId = $localPerson->getId();

    $existingMatchHistory = \Civi\Api4\OsdiPersonSyncState::get(FALSE)
      ->addWhere('contact_id', '=', $contactId)
      ->execute();

    self::assertCount(0, $existingMatchHistory);

    // FIRST SYNC
    $pair = self::$syncer->matchAndSyncIfEligible($localPerson);

    $remotePeopleWithEmail1 = self::$remoteSystem->find(
      'osdi:people',
      [['email', 'eq', $emailOne]]);

    /** @var \Civi\Osdi\ActionNetwork\Object\Person $originalRemotePerson */
    $originalRemotePerson = self::$createdRemotePeople[] =
      $remotePeopleWithEmail1->filteredFirst();

    self::assertEquals($firstNameOne, $originalRemotePerson->givenName->get());

    $emailTwo = microtime(TRUE) . "-$emailOne";
    $nameTwo = "Bride of $firstNameOne";

    $searchResults = self::$remoteSystem->find(
      'osdi:people',
      [['email', 'eq', $emailTwo]]);

    $this->assertEquals(0,
      $searchResults->filteredCurrentCount(),
      'the new email address should not already be in use');

    $localPerson->emailEmail->set($emailTwo);
    $localPerson->firstName->set($nameTwo);
    $localPerson->save();

    $syncResult = $pair->getResultStack()->getLastOfType(Sync::class);
    $syncState = $syncResult->getState();
    $syncState->setLocalPostSyncModifiedTime(
      strtotime($localPerson->modifiedDate->get()) - 1);
    $syncState->save();

    // SYNC CHANGES
    self::$syncer->matchAndSyncIfEligible($localPerson);

    // "find" on Action Network is sometimes slow to catch up
    $startTime = microtime(TRUE);
    while ((microtime(TRUE) - $startTime) < 1.5) {
      $remotePeopleWithEmail2 = self::$remoteSystem->find(
        'osdi:people',
        [['email', 'eq', $emailTwo]]);
      if ($remotePeopleWithEmail2->rawCurrentCount() > 0) {
        break;
      }
      usleep(200000);
    }

    self::$createdRemotePeople[] = $remotePeopleWithEmail2->rawFirst();

    $changedPerson =
      ANPerson::loadFromId($originalRemotePerson->getId(), self::$remoteSystem);
    $changedPersonEmail = $changedPerson->emailAddress->get();
    $changedPersonName = $changedPerson->givenName->get();

    // CLEAN UP BEFORE ASSERTING

    $originalRemotePerson->emailAddress->set($emailOne);
    $originalRemotePerson->save();

    self::assertEquals($emailTwo,
      $changedPersonEmail,
      'Email change should work because there is no address conflict');

    self::assertEquals($nameTwo,
      $changedPersonName,
      'Name should have changed too');
  }

  public function testSyncFromLocalIfNeeded_ChangedEmail_Failure() {
    $firstNameOne = 'Bee';
    $lastName = 'Bim';
    $emailOne = 'bop@yum.com';
    $localPerson = Factory::make('LocalObject', 'Person');
    $localPerson->firstName->set($firstNameOne);
    $localPerson->lastName->set($lastName);
    $localPerson->emailEmail->set($emailOne);
    $localPerson->save();
    $contactId = $localPerson->getId();

    $existingMatchHistory = \Civi\Api4\OsdiPersonSyncState::get(FALSE)
      ->addWhere('contact_id', '=', $contactId)
      ->execute();

    self::assertCount(0, $existingMatchHistory);

    // FIRST SYNC
    $pairBeforeChange = self::$syncer->matchAndSyncIfEligible($localPerson);
    $syncResult = $pairBeforeChange->getResultStack()->getLastOfType(Sync::class);
    $syncState = $syncResult->getState();

    $remotePeopleWithEmail1 = self::$remoteSystem->find(
      'osdi:people',
      [['email', 'eq', $emailOne]]);

    /** @var \Civi\Osdi\ActionNetwork\Object\Person $originalRemotePerson */
    $originalRemotePerson = self::$createdRemotePeople[] =
      $remotePeopleWithEmail1->filteredFirst();

    self::assertEquals($firstNameOne, $originalRemotePerson->givenName->get());

    // CONFLICTING PERSON

    $emailTwo = "different-$emailOne";
    $nameTwo = "Not $firstNameOne";

    $scratchPerson = new ANPerson(self::$remoteSystem);
    $scratchPerson->emailAddress->set($emailTwo);
    $scratchPerson->givenName->set($nameTwo);
    self::$createdRemotePeople[] = $scratchPerson->save();

    // CHANGE EMAIL LOCALLY

    $localPerson->emailEmail->set($emailTwo);
    $localPerson->firstName->set($nameTwo);
    $localPerson->save();

    $syncState->setLocalPostSyncModifiedTime(
      strtotime($localPerson->modifiedDate->get()) - 1);
    $syncState->save();

    // SYNC CHANGES
    $pairAfterChange = self::$syncer->matchAndSyncIfEligible($localPerson);

    $resultStack = $pairAfterChange->getResultStack();
    $fetchFindMatchResult = $resultStack->getLastOfType(FetchOldOrFindNewMatch::class);
    $mapAndWriteResult = $resultStack->getLastOfType(MapAndWrite::class);
    $syncEligibleResult = $resultStack->getLastOfType(SyncEligibility::class);
    $syncResult = $resultStack->getLastOfType(Sync::class);
    $savedMatch = $syncResult->getState();

    self::assertEquals(FetchOldOrFindNewMatch::FETCHED_SAVED_MATCH, $fetchFindMatchResult->getStatusCode());
    self::assertEquals(SyncEligibility::ELIGIBLE, $syncEligibleResult->getStatusCode());
    self::assertEquals(MapAndWrite::SAVE_ERROR, $mapAndWriteResult->getStatusCode(),
      "An Action Network person's "
      . 'email address cannot be changed to an address that already belongs to '
      . 'another person, so the sync should fail.');
    self::assertEquals(Sync::ERROR, $syncResult->getStatusCode());
    self::assertEquals(\Civi\Osdi\PersonSyncState::class, get_class($savedMatch));

    $personAfterAttemptedSync =
      ANPerson::loadFromId($originalRemotePerson->getId(), self::$remoteSystem);
    $personAfterAttemptedSyncEmail = $personAfterAttemptedSync->emailAddress->get();
    $personAfterAttemptedSyncName = $personAfterAttemptedSync->givenName->get();

    self::assertEquals($emailOne,
      $personAfterAttemptedSyncEmail,
      'Email change should not have been successful');

    self::assertEquals($firstNameOne,
      $personAfterAttemptedSyncName,
      'Name should not have changed either');

    $syncState = \Civi\Osdi\PersonSyncState::getForLocalPerson(
      $localPerson,
      self::$syncer->getSyncProfile()['id']
    );
    self::assertEquals('Civi\Osdi\Result\MapAndWrite::error during save',
      $syncState->getSyncStatus());
  }

}
