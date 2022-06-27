<?php

use Civi\Osdi\ActionNetwork\Object\Person as ANPerson;
use CRM_OSDI_Fixture_PersonMatching as PersonMatchFixture;

/**
 * @group headless
 */
class CRM_OSDI_ActionNetwork_PersonSyncerTest extends PHPUnit\Framework\TestCase implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\TransactionalInterface {

  private static array $syncProfile;

  private static \Civi\Osdi\ActionNetwork\Syncer\Person $syncer;

  private static \Civi\Osdi\ActionNetwork\RemoteSystem $remoteSystem;

  private array $createdRemotePeople = [];

  private array $createdContacts = [];

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public static function setUpBeforeClass(): void {
    self::$remoteSystem = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    PersonMatchFixture::$personClass = \Civi\Osdi\ActionNetwork\Object\Person::class;
    PersonMatchFixture::$remoteSystem = self::$remoteSystem;

    self::$syncProfile = CRM_OSDI_ActionNetwork_TestUtils::createSyncProfile();


    self::$syncer = new \Civi\Osdi\ActionNetwork\Syncer\Person(self::$remoteSystem);

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

  /** @noinspection PhpParamsInspection */
  public function testConstructorRequiresRemoteSystem() {
    $this->expectException('ArgumentCountError');
    $syncer = new \Civi\Osdi\ActionNetwork\Syncer\Person();
  }

  public function testRemoteSystemIsSettable() {
    $syncer = new \Civi\Osdi\ActionNetwork\Syncer\Person(self::$remoteSystem);
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

  public function testGetOrCreatePairIncoming() {
    // SETUP

    $remotePerson = PersonMatchFixture::makeNewOsdiPersonWithFirstLastEmail()->save();
    $this->createdRemotePeople[] = $remotePerson;

    // PRE-ASSERTS

    $existingMatchHistory = \Civi\Api4\OsdiPersonSyncState::get(FALSE)
      ->addWhere('remote_person_id', '=', $remotePerson->getId())
      ->execute();

    self::assertCount(0, $existingMatchHistory);

    // TEST PROPER

    $pair = self::$syncer->getOrCreateLocalRemotePair(
      \Civi\Osdi\ActionNetwork\Syncer\Person::inputTypeActionNetworkPersonObject,
      $remotePerson);

    self::assertEquals(\Civi\Osdi\LocalRemotePair::class, get_class($pair));
    self::assertFalse($pair->isError());
    self::assertEquals('created matching object', $pair->getMessage());

    /** @var \Civi\Osdi\LocalObject\Person $createdLocalObject */
    $createdLocalObject = $pair->getLocalObject()->loadOnce();
    $this->createdContacts[] = ['id' => $createdLocalObject->getId()];

    self::assertEquals(\Civi\Osdi\LocalObject\Person::class, get_class($createdLocalObject));

    self::assertEquals($remotePerson->givenName->get(), $createdLocalObject->firstName->get());
    self::assertEquals($remotePerson->familyName->get(), $createdLocalObject->lastName->get());
    self::assertEquals($remotePerson->emailAddress->get(), $createdLocalObject->emailEmail->get());

    $pair = self::$syncer->getOrCreateLocalRemotePair(
      \Civi\Osdi\ActionNetwork\Syncer\Person::inputTypeActionNetworkPersonObject,
      $remotePerson);

    self::assertFalse($pair->isError());
    self::assertEquals('fetched saved match', $pair->getMessage());
    self::assertEquals($createdLocalObject->getId(), $pair->getLocalObject()->getId());

    $savedMatch = $pair->getPersonSyncState();

    self::assertArrayHasKey('id', $savedMatch);

    \Civi\Api4\OsdiPersonSyncState::delete(FALSE)
      ->addWhere('id', '=', $savedMatch['id'])
      ->execute();

    $pair = self::$syncer->getOrCreateLocalRemotePair(
      \Civi\Osdi\ActionNetwork\Syncer\Person::inputTypeActionNetworkPersonObject,
      $remotePerson);

    self::assertFalse($pair->isError());
    self::assertEquals('found new match with existing object', $pair->getMessage());
    self::assertEquals($createdLocalObject->getId(), $pair->getLocalObject()->getId());
    self::assertEquals($createdLocalObject->emailEmail->get(), $pair->getLocalObject()->emailEmail->get());
  }

  public function testGetOrCreatePairOutgoing() {
    // SETUP

    $firstName = 'Bee';
    $lastName = 'Bim';
    $emailAddress = 'bop' . microtime() . '@yum.com';
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
      'osdi:people', [['email', 'eq', $emailAddress]]);

    self::assertEquals(0, $remotePeopleWithTheEmail->rawCurrentCount());

    // TEST PROPER

    $pair = self::$syncer->getOrCreateLocalRemotePair(
      \Civi\Osdi\ActionNetwork\Syncer\Person::inputTypeLocalContactId,
      $contact['id']);

    self::assertEquals(\Civi\Osdi\LocalRemotePair::class, get_class($pair));
    self::assertFalse($pair->isError());
    self::assertEquals('created matching object', $pair->getMessage());

    $createdRemoteObject = $this->createdRemotePeople[] = $pair->getRemoteObject();

    self::assertEquals(
      \Civi\Osdi\ActionNetwork\Object\Person::class,
      get_class($createdRemoteObject));
    /** @var \Civi\Osdi\ActionNetwork\Object\Person $createdRemoteObject */
    self::assertEquals($firstName, $createdRemoteObject->givenName->get());
    self::assertEquals($lastName, $createdRemoteObject->familyName->get());
    self::assertEquals($emailAddress, $createdRemoteObject->emailAddress->get());

    $pair = self::$syncer->getOrCreateLocalRemotePair(
      \Civi\Osdi\ActionNetwork\Syncer\Person::inputTypeLocalContactId,
      $contact['id']);

    self::assertFalse($pair->isError());
    self::assertEquals('fetched saved match', $pair->getMessage());
    self::assertEquals($createdRemoteObject->getAll(), $pair->getRemoteObject()->getAll());

    $savedMatch = $pair->getPersonSyncState();

    self::assertArrayHasKey('id', $savedMatch);

    \Civi\Api4\OsdiPersonSyncState::delete(FALSE)
      ->addWhere('id', '=', $savedMatch['id'])
      ->execute();

    $pair = self::$syncer->getOrCreateLocalRemotePair(
      \Civi\Osdi\ActionNetwork\Syncer\Person::inputTypeLocalContactId,
      $contact['id']);

    self::assertFalse($pair->isError());
    self::assertEquals('found new match with existing object', $pair->getMessage());
    self::assertEquals($createdRemoteObject->getId(), $pair->getRemoteObject()->getId());
    self::assertEquals($createdRemoteObject->emailAddress->get(), $pair->getRemoteObject()->emailAddress->get());
  }

  public function testGetOrCreatePair_ContactIsDeletedButASavedMatchStillExists() {
    // SETUP

    $remotePerson = PersonMatchFixture::makeNewOsdiPersonWithFirstLastEmail()->save();
    $this->createdRemotePeople[] = $remotePerson;

    $pair = self::$syncer->oneWaySync(
      \Civi\Osdi\ActionNetwork\Syncer\Person::inputTypeActionNetworkPersonObject,
      $remotePerson
    );

    self::assertFalse($pair->isError());

    $createdLocalObject = $pair->getLocalObject();
    $this->createdContacts[] = ['id' => $createdLocalObject->getId()];

    // TEST PROPER

    $pair = self::$syncer->getOrCreateLocalRemotePair(
      \Civi\Osdi\ActionNetwork\Syncer\Person::inputTypeActionNetworkPersonObject,
      $remotePerson);

    self::assertEquals('fetched saved match', $pair->getMessage());

    \Civi\Api4\Contact::delete(FALSE)
      ->addWhere('id', '=', $createdLocalObject->getId())
      ->execute();

    $pair = self::$syncer->getOrCreateLocalRemotePair(
      \Civi\Osdi\ActionNetwork\Syncer\Person::inputTypeActionNetworkPersonObject,
      $remotePerson);

    self::assertEquals('created matching object', $pair->getMessage());
  }

  public function testSyncNewIncoming() {
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

    self::$syncer->oneWaySync(\Civi\Osdi\ActionNetwork\Syncer\Person::inputTypeActionNetworkPersonObject, $person);

    $localContactsWithTheEmail = \Civi\Api4\Contact::get()
      ->addJoin('Email AS email', 'LEFT')
      ->addWhere('email.email', '=', $emailAddress)
      ->execute();
    $this->createdContacts = array_merge($this->createdContacts,
     $localContactsWithTheEmail->column('id'));

    self::assertEquals(1, $localContactsWithTheEmail->count());

    $contact = $localContactsWithTheEmail->single();

    self::assertEquals($firstName, $contact['first_name']);

    self::assertEquals($lastName, $contact['last_name']);
  }

  public function testSyncNewOutgoing() {
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

    if ($remotePeopleWithTheEmail->filteredCurrentCount()) {
      $remotePeopleWithTheEmail->filteredFirst()->delete();
      $remotePeopleWithTheEmail = self::$remoteSystem->find(
        'osdi:people',
        [['email', 'eq', $emailAddress]]);
    }

    self::assertEquals(0, $remotePeopleWithTheEmail->filteredCurrentCount());

    // TEST PROPER

    $result = self::$syncer->oneWaySync(\Civi\Osdi\ActionNetwork\Syncer\Person::inputTypeLocalContactId, $contact['id']);

    self::assertEquals(\Civi\Osdi\SyncResult::class, get_class($result));
    self::assertEquals(\Civi\Osdi\SyncResult::SUCCESS, $result->getStatusCode());


    $remotePeopleWithTheEmail = self::$remoteSystem->find(
      'osdi:people',
      [['email', 'eq', $emailAddress]]);
    $this->createdRemotePeople[] = $remotePeopleWithTheEmail->filteredFirst();

    self::assertEquals(1, $remotePeopleWithTheEmail->filteredCurrentCount());

    self::assertEquals(
      $firstName,
      $remotePeopleWithTheEmail->filteredFirst()->givenName->get());

    self::assertEquals(
      $lastName,
      $remotePeopleWithTheEmail->filteredFirst()->familyName->get());
  }

  public function testSyncChangedNameIncoming() {
    // SETUP

    /** @var \Civi\Osdi\ActionNetwork\Object\Person $originalRemotePerson */
    [$originalRemotePerson, $contactId] =
      PersonMatchFixture::setUpExactlyOneMatchByEmailAndName(self::$remoteSystem);
    $this->createdRemotePeople[] = $originalRemotePerson;
    $this->createdContacts[] = $contactId;

    $originalContact = PersonMatchFixture::civiApi4GetSingleContactById($contactId);

    // TEST PROPER

    $changedFirstName = $originalRemotePerson->givenName->get() . ' plus this';
    $changedRemotePerson = $originalRemotePerson;
    $changedRemotePerson->givenName->set($changedFirstName);
    $changedRemotePerson->save();

    self::assertNotEquals(
      $changedFirstName,
      $originalContact['first_name']);

    self::$syncer->oneWaySync(\Civi\Osdi\ActionNetwork\Syncer\Person::inputTypeActionNetworkPersonObject, $changedRemotePerson);

    $syncedContact = PersonMatchFixture::civiApi4GetSingleContactById($contactId);

    self::assertEquals(
      $changedFirstName,
      $syncedContact['first_name']);

    self::assertEquals(
      $originalRemotePerson->familyName->get(),
      $syncedContact['last_name']);
  }

  public function testChangedNameIncomingDoesNotDuplicateAddressesEtc() {
    self::markTestIncomplete();
  }

  public function testSyncChangedNameOutgoing() {
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

    self::$syncer->oneWaySync(\Civi\Osdi\ActionNetwork\Syncer\Person::inputTypeLocalContactId, $contactId);

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

  public function testSyncAddedAddressIncoming() {
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

    self::assertNotEquals($newStreet,
      $originalContact['address.street_address'] ?? NULL);

    self::$syncer->oneWaySync(\Civi\Osdi\ActionNetwork\Syncer\Person::inputTypeActionNetworkPersonObject, $remotePerson);

    $syncedContact = PersonMatchFixture::civiApi4GetSingleContactById($contactId);

    self::assertEquals(
      $newStreet,
      $syncedContact['address.street_address']);
  }

  public function testSyncAddedAddressOutgoingSuccess() {
    // SETUP

    [$originalRemotePerson, $contactId] =
      PersonMatchFixture::setUpExactlyOneMatchByEmailAndName(self::$remoteSystem);
    $this->createdRemotePeople[] = $originalRemotePerson;
    $this->createdContacts[] = $contactId;

    $newStreet = '123 Test St.';

    // TEST PROPER

    $reFetchedRemotePerson = self::$remoteSystem
      ->fetchPersonById($originalRemotePerson->getId());

    self::assertNotEquals($newStreet, $reFetchedRemotePerson->postalStreet->get());

    \Civi\Api4\Address::create()
      ->addValue('contact_id', $contactId)
      ->addValue('location_type_id', 1)
      ->addValue('is_primary', TRUE)
      ->addValue('street_address', $newStreet)
      ->addValue('postal_code', '65542')
      ->execute();

    self::$syncer->oneWaySync(\Civi\Osdi\ActionNetwork\Syncer\Person::inputTypeLocalContactId, $contactId);

    $reFetchedRemotePerson = self::$remoteSystem
      ->fetchPersonById($originalRemotePerson->getId());

    self::assertEquals($newStreet, $reFetchedRemotePerson->postalStreet->get());
  }

  public function testSyncAddedAddressOutgoingFailsBecauseNoZip() {
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

    self::$syncer->oneWaySync(\Civi\Osdi\ActionNetwork\Syncer\Person::inputTypeLocalContactId, $contactId);

    $reFetchedRemotePerson = self::$remoteSystem
      ->fetchPersonById($originalRemotePerson->getId());

    self::assertNull(
      $reFetchedRemotePerson
        ->postalRegion->get() ?? NULL);
  }

  public function testSyncChangedEmailOutgoingSuccess() {
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
    self::$syncer->oneWaySync(\Civi\Osdi\ActionNetwork\Syncer\Person::inputTypeLocalContactId, $contact['id']);

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

    // SYNC CHANGES
    self::$syncer->oneWaySync(\Civi\Osdi\ActionNetwork\Syncer\Person::inputTypeLocalContactId, $contact['id']);
    // the system sometimes is slow to catch up
    usleep(500000);

    $remotePeopleWithEmail2 = self::$remoteSystem->find(
      'osdi:people',
      [['email', 'eq', $emailTwo]]);
    $this->createdRemotePeople[] = $remotePeopleWithEmail2->filteredFirst();

    $changedPerson = self::$remoteSystem->fetchPersonById(
      $originalPerson->getId()
    );
    $changedPersonEmail = $changedPerson->emailAddress->get();
    $changedPersonName = $changedPerson->givenName->get();

    // CLEAN UP BEFORE ASSERTING

    $changedPerson->emailAddress->set($emailOne);
    $changedPerson->save();

    self::assertEquals($emailTwo,
      $changedPersonEmail,
      'Email change should work because there is no address conflict');

    self::assertEquals($nameTwo,
      $changedPersonName,
      'Name should have changed too');
  }

  public function testSyncChangedEmailOutgoingFailure() {
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
    self::$syncer->oneWaySync(\Civi\Osdi\ActionNetwork\Syncer\Person::inputTypeLocalContactId, $contact['id']);

    $remotePeopleWithEmail1 = self::$remoteSystem->find(
      'osdi:people',
      [['email', 'eq', $emailOne]]);
    /** @var \Civi\Osdi\ActionNetwork\Object\Person $originalPerson */
    $originalPerson = $this->createdRemotePeople[] = $remotePeopleWithEmail1->filteredFirst();

    self::assertEquals($firstNameOne, $originalPerson->givenName->get());

    // CONFLICTING PERSON

    $emailTwo = "different-$emailOne";
    $nameTwo = "Not $firstNameOne";

    $scratchPerson = new \Civi\Osdi\ActionNetwork\Object\Person(self::$remoteSystem);
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
    $result = self::$syncer->oneWaySync(\Civi\Osdi\ActionNetwork\Syncer\Person::inputTypeLocalContactId, $contact['id']);

    self::assertTrue($result->isError());

    $changedPerson = self::$remoteSystem->fetchPersonById(
      $originalPerson->getId()
    );
    $changedPersonEmail = $changedPerson->emailAddress->get();
    $changedPersonName = $changedPerson->givenName->get();

    self::assertEquals($emailOne,
      $changedPersonEmail,
      'Email change should not have been successful');

    self::assertEquals($firstNameOne,
      $changedPersonName,
      'Name should not have changed either');

    $savedMatch = self::$syncer->getSavedMatchForLocalContact($contact['id']);
    self::assertEquals('error', $savedMatch['sync_status']);
  }

}
