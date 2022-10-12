<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer\Person;

use Civi;
use Civi\Osdi\ActionNetwork\Object\Person as ANPerson;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Result\FetchOldOrFindNewMatch;
use Civi\Osdi\Result\MapAndWrite;
use Civi\Osdi\Result\Sync;
use Civi\Osdi\Result\SyncEligibility;
use CRM_OSDI_ActionNetwork_TestUtils;
use CRM_OSDI_Fixture_PersonMatching as PersonMatchFixture;
use CRM_OSDI_FixtureHttpClient;
use Jsor;
use PHPUnit;

/**
 * @group headless
 */
class PersonBasicTest extends PHPUnit\Framework\TestCase implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\TransactionalInterface {

  private static array $syncProfile;

  private static PersonBasic $syncer;

  private static \Civi\Osdi\ActionNetwork\RemoteSystem $remoteSystem;

  private array $createdRemotePeople = [];

  private array $createdContacts = [];

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public static function setUpBeforeClass(): void {
    self::$remoteSystem = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    PersonMatchFixture::$personClass = ANPerson::class;
    PersonMatchFixture::$remoteSystem = self::$remoteSystem;

    self::$syncProfile = CRM_OSDI_ActionNetwork_TestUtils::createSyncProfile();

    self::$syncer = new PersonBasic(self::$remoteSystem);

    self::$syncer->setSyncProfile(self::$syncProfile);

    \Civi\Api4\OsdiPersonSyncState::delete(FALSE)
      ->addWhere('id', '>', 0)
      ->execute();
  }

  protected function setUp(): void {
    CRM_OSDI_FixtureHttpClient::resetHistory();
  }

  protected function tearDown(): void {
    while ($person = array_pop($this->createdRemotePeople)) {
      $person->delete();
    }

    while ($contactId = array_pop($this->createdContacts)) {
      \Civi\Api4\Email::delete(FALSE)
        ->addWhere('contact_id', '=', $contactId)
        ->execute();
      \Civi\Api4\Contact::delete(FALSE)
        ->addWhere('id', '=', $contactId)
        ->execute();
    }
  }

  public static function tearDownAfterClass(): void {
    \Civi\Api4\OsdiSyncProfile::delete(FALSE)
      ->addWhere('id', '=', self::$syncProfile['id'])
      ->execute();
  }

  public function testMatchAndSyncIfEligible_WriteNewTwin_FromLocal() {
    $syncer = self::$syncer;
    $localPerson = new Civi\Osdi\LocalObject\PersonBasic();
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
    self::assertEquals(Civi\Osdi\PersonSyncState::class, get_class($savedMatch));
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
    self::assertEquals(Civi\Osdi\PersonSyncState::class, get_class($savedMatch));
    self::assertNotNull($pair->getRemoteObject()->getId());

    self::assertEquals('testMatchAndSyncIfEligible_FromLocal',
      $pair->getLocalObject()->firstName->get());

    // THIRD SYNC

    /** @var \Civi\Osdi\PersonSyncState $savedMatch */
    $savedMatch->setLocalPostSyncModifiedTime(
      $savedMatch->getLocalPostSyncModifiedTime() - 1);

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
    self::assertEquals(Civi\Osdi\PersonSyncState::class, get_class($savedMatch));
    self::assertNotNull($pair->getRemoteObject()->getId());

    self::assertEquals('testMatchAndSyncIfEligible_FromLocal',
      $pair->getRemoteObject()->givenName->get());

    // FOURTH SYNC
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
    self::assertEquals(MapAndWrite::WROTE_CHANGES, $mapAndWriteResult->getStatusCode());
    self::assertEquals(SyncEligibility::ELIGIBLE, $syncEligibleResult->getStatusCode());
    self::assertEquals(Sync::SUCCESS, $syncResult->getStatusCode());
    self::assertEquals(Civi\Osdi\PersonSyncState::class, get_class($savedMatch));
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
    self::assertEquals(Civi\Osdi\PersonSyncState::class, get_class($savedMatch));
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
    self::assertEquals(Civi\Osdi\PersonSyncState::class, get_class($savedMatch));
    self::assertNotNull($pair->getLocalObject()->getId());

    self::assertEquals('testMatchAndSyncIfEligible_FromRemote',
      $pair->getLocalObject()->firstName->get());

    // THIRD SYNC

    /** @var \Civi\Osdi\PersonSyncState $savedMatch */
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
    self::assertEquals(Civi\Osdi\PersonSyncState::class, get_class($savedMatch));
    self::assertNotNull($pair->getLocalObject()->getId());

    self::assertEquals('testMatchAndSyncIfEligible_FromRemote',
      $pair->getLocalObject()->firstName->get());

    // FOURTH SYNC
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
    self::assertEquals(MapAndWrite::WROTE_CHANGES, $mapAndWriteResult->getStatusCode());
    self::assertEquals(SyncEligibility::ELIGIBLE, $syncEligibleResult->getStatusCode());
    self::assertEquals(Sync::SUCCESS, $syncResult->getStatusCode());
    self::assertEquals(Civi\Osdi\PersonSyncState::class, get_class($savedMatch));
    self::assertNotNull($pair->getLocalObject()->getId());

    self::assertEquals('testMatchAndSyncIfEligible_FromRemote (new name)',
      $pair->getLocalObject()->firstName->get());
  }

  public function testDeletedPersonDoesNotGetRecreated() {
    $localPerson = new Civi\Osdi\LocalObject\PersonBasic();
    $localPerson->firstName->set('Cheese');
    $localPerson->emailEmail->set('bitz@bop.com');
    $localPerson->save();
    $civiToAnPair = self::$syncer->matchAndSyncIfEligible($localPerson);
    $remotePerson = $civiToAnPair->getRemoteObject();

    self::assertFalse($civiToAnPair->isError());
    self::assertNotNull($remotePerson->getId());
    self::assertEquals('bitz@bop.com', $remotePerson->emailAddress->get());

    self::$syncer->syncDeletion($civiToAnPair);

    $anToCiviPair = self::$syncer->matchAndSyncIfEligible($remotePerson);

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
    self::assertEquals(Civi\Osdi\PersonSyncState::class, get_class($savedMatch));
    self::assertEquals(
      $civiToAnPair->getLocalObject()->getId(), $anToCiviPair->getLocalObject()->getId());
  }

  public function testRemoteSystemIsSettable() {
    $syncer = new PersonBasic(self::$remoteSystem);
    $originalSystem = $syncer->getRemoteSystem();
    $defaultSystemAEP = $originalSystem->getEntryPoint();

    $differentSystemAEP = $defaultSystemAEP . 'with suffix';
    $client = new Jsor\HalClient\HalClient(
        $differentSystemAEP, new CRM_OSDI_FixtureHttpClient()
    );
    $differentSystem = new Civi\Osdi\ActionNetwork\RemoteSystem(
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

    $person = $this->createdRemotePeople[] = new ANPerson(self::$remoteSystem);
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

    $result = self::$syncer->syncFromRemoteIfNeeded($person);

    self::assertEquals(\Civi\Osdi\Result\Sync::class, get_class($result));
    self::assertEquals(\Civi\Osdi\Result\Sync::SUCCESS, $result->getStatusCode());

    $localContactsWithTheEmail = \Civi\Api4\Contact::get()
      ->addJoin('Email AS email', 'LEFT')
      ->addWhere('email.email', '=', $emailAddress)
      ->execute();
    $this->createdContacts = array_merge($this->createdContacts,
        $localContactsWithTheEmail->column('id'));

    self::assertEquals(1, $localContactsWithTheEmail->count());

    $contact = $localContactsWithTheEmail->single();

    self::assertEquals($result->getLocalObject()->getId(), $contact['id']);
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
    $this->createdContacts[] = $contact['id'];

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

    $localPerson = Civi\Osdi\LocalObject\PersonBasic::fromId($contact['id']);
    $result = self::$syncer->syncFromLocalIfNeeded($localPerson);

    self::assertEquals(\Civi\Osdi\Result\Sync::class, get_class($result));
    self::assertEquals(\Civi\Osdi\Result\Sync::SUCCESS, $result->getStatusCode());

    $remotePeopleWithTheEmail = self::$remoteSystem->find(
        'osdi:people',
        [['email', 'eq', $emailAddress]]);
    $remotePersonWithTheEmail = $remotePeopleWithTheEmail->filteredFirst();
    $this->createdRemotePeople[] = $remotePersonWithTheEmail;

    self::assertEquals(1, $remotePeopleWithTheEmail->filteredCurrentCount());

    self::assertEquals($result->getRemoteObject()->getId(),
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
    $this->createdRemotePeople[] = $originalRemotePerson->save();
    $pair = self::$syncer->makeLocalRemotePair(NULL, $originalRemotePerson);
    $syncResult = self::$syncer->oneWayWriteFromRemote($pair);

    self::assertNotTrue($syncResult->isError());

    $originalLocalPerson = $syncResult->getLocalObject();
    $contactId = $originalLocalPerson->getId();
    $this->createdContacts[] = $contactId;
    $originalContact = PersonMatchFixture::civiApi4GetSingleContactById($contactId);

    // TEST PROPER

    $syncResult = self::$syncer->syncFromRemoteIfNeeded($originalRemotePerson);

    self::assertEquals(\Civi\Osdi\Result\Sync::NO_SYNC_NEEDED, $syncResult->getStatusCode());

    $syncState = $syncResult->getState();
    $syncState->setRemotePostSyncModifiedTime(
      $syncState->getRemotePostSyncModifiedTime() - 1);
    $syncState->save();

    $syncResult = self::$syncer->syncFromRemoteIfNeeded($originalRemotePerson);

    self::assertEquals(\Civi\Osdi\Result\Sync::SUCCESS, $syncResult->getStatusCode());

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

    $syncResult = self::$syncer->syncFromRemoteIfNeeded($changedRemotePerson);

    self::assertEquals(\Civi\Osdi\Result\Sync::SUCCESS, $syncResult->getStatusCode());

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
    $this->createdRemotePeople[] = $originalRemotePerson;
    $this->createdContacts[] = $contactId;

    $originalFirstName = $originalRemotePerson->givenName->get();

    // TEST PROPER

    $changedFirstName = $originalFirstName . ' plus this';

    \Civi\Api4\Contact::update(FALSE)
      ->addWhere('id', '=', $contactId)
      ->addValue('first_name', $changedFirstName)
      ->execute();

    $localPerson = new Civi\Osdi\LocalObject\PersonBasic($contactId);
    $syncResult = self::$syncer->syncFromLocalIfNeeded($localPerson);

    $remotePeopleWithTheEmail = self::$remoteSystem->find(
        'osdi:people',
        [['email', 'eq', $originalRemotePerson->emailAddress->get()]]);
    $this->createdRemotePeople[] = $remotePeopleWithTheEmail->filteredFirst();

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
    $this->createdRemotePeople[] = $remotePerson;
    $this->createdContacts[] = $contactId;

    $originalContact = PersonMatchFixture::civiApi4GetSingleContactById($contactId);

    // TEST PROPER

    $newStreet = '123 Test St.';
    $remotePerson->postalStreet->set($newStreet);
    $remotePerson->postalCode->set('83001');
    $remotePerson->save();

    self::assertEquals('83001', $remotePerson->postalCode->get());
    self::assertNotEquals($newStreet,
        $originalContact['address.street_address'] ?? NULL);

    $syncResult = self::$syncer->syncFromRemoteIfNeeded($remotePerson);

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
    $this->createdRemotePeople[] = $originalRemotePerson;
    $this->createdContacts[] = $contactId;

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

    $localPerson = new Civi\Osdi\LocalObject\PersonBasic($contactId);
    self::$syncer->syncFromLocalIfNeeded($localPerson);

    $reFetchedRemotePerson =
            ANPerson::loadFromId($originalRemotePerson->getId(), self::$remoteSystem);

    self::assertEquals($newStreet, $reFetchedRemotePerson->postalStreet->get());
  }

  public function testSyncFromLocalIfNeeded_AddedAddress_FailsBecauseNoZip() {
    // SETUP

    [$originalRemotePerson, $contactId] =
            PersonMatchFixture::setUpExactlyOneMatchByEmailAndName(self::$remoteSystem);
    $this->createdRemotePeople[] = $originalRemotePerson;
    $this->createdContacts[] = $contactId;

    // TEST PROPER

    \Civi\Api4\Address::create()
      ->addValue('contact_id', $contactId)
      ->addValue('location_type_id', 1)
      ->addValue('is_primary', TRUE)
      ->addValue('city', 'Albany')
      ->addValue('state_province_id:name', 'Georgia')
      ->addValue('postal_code', '')
      ->execute();

    $localPerson = new Civi\Osdi\LocalObject\PersonBasic($contactId);
    self::$syncer->syncFromLocalIfNeeded($localPerson);

    $reFetchedRemotePerson =
            ANPerson::loadFromId($originalRemotePerson->getId(), self::$remoteSystem);

    self::assertEquals('KS',
        $reFetchedRemotePerson->postalRegion->get() ?? NULL);
  }

  public function testSyncFromLocalIfNeeded_ChangedEmail_Success() {
    $firstNameOne = 'Bee';
    $lastName = 'Bim';
    $emailOne = 'bop@yum.com';
    $contact = PersonMatchFixture::civiApi4CreateContact(
        $firstNameOne,
        $lastName,
        $emailOne)
      ->single();
    $this->createdContacts[] = $contact['id'];

    $existingMatchHistory = \Civi\Api4\OsdiPersonSyncState::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->execute();

    self::assertCount(0, $existingMatchHistory);

    // FIRST SYNC
    $localPerson = new Civi\Osdi\LocalObject\PersonBasic($contact['id']);
    $syncResult = self::$syncer->syncFromLocalIfNeeded($localPerson);

    $remotePeopleWithEmail1 = self::$remoteSystem->find(
        'osdi:people',
        [['email', 'eq', $emailOne]]);
    /** @var \Civi\Osdi\ActionNetwork\Object\Person $originalPerson */
    $originalPerson = $this->createdRemotePeople[] = $remotePeopleWithEmail1->filteredFirst();

    self::assertEquals($firstNameOne, $originalPerson->givenName->get());

    $emailTwo = microtime(TRUE) . "-$emailOne";
    $nameTwo = "Bride of $firstNameOne";

    $searchResults = self::$remoteSystem->find(
        'osdi:people',
        [['email', 'eq', $emailTwo]]);
    $this->assertEquals(0,
        $searchResults->filteredCurrentCount(),
        'the new email address should not already be in use');

    $civiUpdateEmail = \Civi\Api4\Email::update(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->addValue('email', $emailTwo)
      ->execute();
    self::assertGreaterThan(0, $civiUpdateEmail->count());

    \Civi\Api4\Contact::update(FALSE)
      ->addWhere('id', '=', $contact['id'])
      ->addValue('first_name', $nameTwo)
      ->execute();

    $syncState = $syncResult->getState();
    $syncState->setLocalPostSyncModifiedTime(
        $syncState->getLocalPostSyncModifiedTime() - 1);
    $syncState->save();

    // SYNC CHANGES
    $localPerson = new Civi\Osdi\LocalObject\PersonBasic($contact['id']);
    self::$syncer->syncFromLocalIfNeeded($localPerson);

    // "find" on Action Network is sometimes slow to catch up
    usleep(500000);
    $remotePeopleWithEmail2 = self::$remoteSystem->find(
        'osdi:people',
        [['email', 'eq', $emailTwo]]);
    $this->createdRemotePeople[] = $remotePeopleWithEmail2->filteredFirst();

    $changedPerson =
            ANPerson::loadFromId($originalPerson->getId(), self::$remoteSystem);
    $changedPersonEmail = $changedPerson->emailAddress->get();
    $changedPersonName = $changedPerson->givenName->get();

    // CLEAN UP BEFORE ASSERTING

    $originalPerson->emailAddress->set($emailOne);
    $originalPerson->save();

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
    $contact = PersonMatchFixture::civiApi4CreateContact(
        $firstNameOne,
        $lastName,
        $emailOne)
      ->single();
    $this->createdContacts[] = $contact['id'];

    $existingMatchHistory = \Civi\Api4\OsdiPersonSyncState::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->execute();

    self::assertCount(0, $existingMatchHistory);

    // FIRST SYNC
    $localPerson = new Civi\Osdi\LocalObject\PersonBasic($contact['id']);
    $pair = self::$syncer->makeLocalRemotePair($localPerson);
    self::$syncer->oneWayWriteFromLocal($pair);

    $remotePeopleWithEmail1 = self::$remoteSystem->find(
        'osdi:people',
        [['email', 'eq', $emailOne]]);
    /** @var \Civi\Osdi\ActionNetwork\Object\Person $originalPerson */
    $originalPerson = $this->createdRemotePeople[] = $remotePeopleWithEmail1->filteredFirst();

    self::assertEquals($firstNameOne, $originalPerson->givenName->get());

    // CONFLICTING PERSON

    $emailTwo = "different-$emailOne";
    $nameTwo = "Not $firstNameOne";

    $scratchPerson = new ANPerson(self::$remoteSystem);
    $scratchPerson->emailAddress->set($emailTwo);
    $scratchPerson->givenName->set($nameTwo);
    $this->createdRemotePeople[] = $scratchPerson->save();

    // CHANGE EMAIL LOCALLY

    $civiUpdateEmail = \Civi\Api4\Email::update(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->addValue('email', $emailTwo)
      ->execute();
    self::assertGreaterThan(0, $civiUpdateEmail->count());

    \Civi\Api4\Contact::update(FALSE)
      ->addWhere('id', '=', $contact['id'])
      ->addValue('first_name', $nameTwo)
      ->execute();

    // SYNC CHANGES
    $localPerson = new Civi\Osdi\LocalObject\PersonBasic($contact['id']);
    $pair = self::$syncer->makeLocalRemotePair($localPerson);
    $result = self::$syncer->oneWayWriteFromLocal($pair);

    self::assertTrue($result->isError());

    $changedPerson =
            ANPerson::loadFromId($originalPerson->getId(), self::$remoteSystem);
    $changedPersonEmail = $changedPerson->emailAddress->get();
    $changedPersonName = $changedPerson->givenName->get();

    self::assertEquals($emailOne,
        $changedPersonEmail,
        'Email change should not have been successful');

    self::assertEquals($firstNameOne,
        $changedPersonName,
        'Name should not have changed either');

    $syncState = \Civi\Osdi\PersonSyncState::getForLocalPerson(
        $localPerson,
        self::$syncer->getSyncProfile()['id']
    );
    self::assertEquals('error', $syncState->getSyncStatus());
  }

}
