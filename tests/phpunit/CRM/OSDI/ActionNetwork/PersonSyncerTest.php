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
    PersonMatchFixture::$personClass = \Civi\Osdi\ActionNetwork\Object\Person::class;

    self::$syncProfile = CRM_OSDI_ActionNetwork_TestUtils::createSyncProfile();

    self::$remoteSystem = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    self::$syncer = new \Civi\Osdi\ActionNetwork\Syncer\Person(self::$remoteSystem);

    self::$syncer->setSyncProfile(self::$syncProfile);

    \Civi\Api4\OsdiMatch::delete(FALSE)
      ->addWhere('id', '>', 0)
      ->execute();
  }

  protected function setUp(): void {
    CRM_OSDI_FixtureHttpClient::resetHistory();
  }

  protected function tearDown(): void {
    while ($person = array_pop($this->createdRemotePeople)) {
      self::$remoteSystem->delete($person);
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

  /*
  public function testGetSavedMatchForLocalContact() {
    $retrievedMatch = self::$syncer->getSavedMatchForLocalContact(1);
    self::assertIsArray($retrievedMatch);
    self::assertEmpty($retrievedMatch);

    $modDate = date_create('-1 week')->format('YmdHis');
    $savedMatch = \Civi\Api4\OsdiMatch::create(FALSE)
      ->addValue('contact_id', 1)
      ->addValue('sync_profile_id', self::$syncProfile['id'])
      ->addValue('remote_person_id', 'foo')
      ->addValue('sync_origin_modified_time', $modDate)
      ->addValue('sync_target_modified_time', $modDate)
      ->addValue('sync_origin', CRM_OSDI_BAO_OSDIMatch::ORIGIN_LOCAL)
      ->execute()->single();

    $retrievedMatch = self::$syncer->getSavedMatchForLocalContact(1);
    self::assertEquals('foo', $retrievedMatch['remote_person_id']);
    self::assertEquals(
      $savedMatch['sync_target_modified_time'],
      date_create($retrievedMatch['sync_target_modified_time'])->format('YmdHis'));
  }

  public function testGetSavedMatchForRemoteContact() {
    $remotePerson = new \Civi\Osdi\Generic\Person();
    $remotePerson->setId('foo');

    $retrievedMatch = self::$syncer->getSavedMatchForRemotePerson($remotePerson);
    self::assertIsArray($retrievedMatch);
    self::assertEmpty($retrievedMatch);

    $modDate = date_create('-1 week')->format('YmdHis');
    $savedMatch = \Civi\Api4\OsdiMatch::create(FALSE)
      ->addValue('contact_id', 1)
      ->addValue('sync_profile_id', self::$syncProfile['id'])
      ->addValue('remote_person_id', 'foo')
      ->addValue('sync_origin_modified_time', $modDate)
      ->addValue('sync_target_modified_time', $modDate)
      ->addValue('sync_origin', CRM_OSDI_BAO_OSDIMatch::ORIGIN_LOCAL)
      ->execute()->single();

    $retrievedMatch = self::$syncer->getSavedMatchForLocalContact(1);
    self::assertEquals(1, $retrievedMatch['contact_id']);
    self::assertEquals(
      $savedMatch['sync_target_modified_time'],
      date_create($retrievedMatch['sync_target_modified_time'])->format('YmdHis'));
  }

  public function testFindMatchForLocalContact() {
    [$emailAddress, $savedRemotePerson, $contactId] =
      PersonMatchFixture::setUpExactlyOneMatchByEmail_DifferentNames(self::$remoteSystem);
    $matchResult = self::$syncer->findRemoteMatchForLocalContact($contactId);
    self::assertEquals(1, $matchResult->count());
    self::assertContains(
      \Civi\Osdi\RemoteObjectInterface::class,
      class_implements($matchResult->matches()[0]));
    self::assertEquals($emailAddress, $matchResult->matches()[0]->getEmailAddress());
    self::assertEquals(
      $savedRemotePerson->get('given_name'),
      $matchResult->matches()[0]->get('given_name')
    );
  }

  public function testFindMatchForRemoteContact() {
    [$emailAddress, $savedRemotePerson, $contactId] =
      PersonMatchFixture::setUpExactlyOneMatchByEmail_DifferentNames(self::$remoteSystem);
    $matchResult = self::$syncer->findLocalMatchForRemotePerson($savedRemotePerson);
    self::assertEquals(1, $matchResult->count());
    self::assertIsArray($matchResult->matches()[0]);
    self::assertEquals($emailAddress, $matchResult->matches()[0]['email.email']);
    self::assertEquals($contactId, $matchResult->matches()[0]['id']);
  }

 */

  public function testSyncNewIncoming() {
    // SETUP

    $firstName = 'Bee';
    $lastName = 'Bim';
    $emailAddress = 'bop@yum.com';

    $person = self::$remoteSystem->save(new ANPerson(NULL, [
      'given_name' => $firstName,
      'family_name' => $lastName,
      'email_addresses' => [['address' => $emailAddress]],
    ]));
    $this->createdRemotePeople[] = $person;

    \Civi\Api4\Email::delete(FALSE)
      ->addWhere('email', '=', $emailAddress)
      ->execute();

    // PRE-ASSERTS

    $existingMatchHistory = \Civi\Api4\OsdiMatch::get(FALSE)
      ->addWhere('remote_person_id', '=', $person->getId())
      ->execute();

    self::assertCount(0, $existingMatchHistory);

    // TEST PROPER

    self::$syncer->oneWaySync('ActionNetwork:Person:Object', $person);

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

    $existingMatchHistory = \Civi\Api4\OsdiMatch::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->execute();

    self::assertCount(0, $existingMatchHistory);

    $remotePeopleWithTheEmail = self::$remoteSystem->find(
      'osdi:people',
      [['email', 'eq', $emailAddress]]);

    if ($remotePeopleWithTheEmail->filteredCurrentCount()) {
      self::$remoteSystem->delete($remotePeopleWithTheEmail->filteredFirst());
      $remotePeopleWithTheEmail = self::$remoteSystem->find(
        'osdi:people',
        [['email', 'eq', $emailAddress]]);
    }

    self::assertEquals(0, $remotePeopleWithTheEmail->filteredCurrentCount());

    // TEST PROPER

    self::$syncer->oneWaySync('Local:Contact:Id', $contact['id']);

    $remotePeopleWithTheEmail = self::$remoteSystem->find(
      'osdi:people',
      [['email', 'eq', $emailAddress]]);
    $this->createdRemotePeople[] = $remotePeopleWithTheEmail->filteredFirst();

    self::assertEquals(1, $remotePeopleWithTheEmail->filteredCurrentCount());

    self::assertEquals(
      $firstName,
      $remotePeopleWithTheEmail->filteredFirst()->get('given_name'));

    self::assertEquals(
      $lastName,
      $remotePeopleWithTheEmail->filteredFirst()->get('family_name'));
  }

  public function testSyncChangedNameIncoming() {
    // SETUP

    [$originalRemotePerson, $contactId] =
      PersonMatchFixture::setUpExactlyOneMatchByEmailAndName(self::$remoteSystem);
    $this->createdRemotePeople[] = $originalRemotePerson;
    $this->createdContacts[] = $contactId;

    $originalContact = PersonMatchFixture::civiApi4GetSingleContactById($contactId);

    // TEST PROPER

    $changedFirstName = $originalRemotePerson->get('given_name') . ' plus this';
    $changedRemotePerson = $originalRemotePerson;
    $changedRemotePerson->set('given_name', $changedFirstName);
    self::$remoteSystem->save($changedRemotePerson);

    self::assertNotEquals(
      $changedFirstName,
      $originalContact['first_name']);

    self::$syncer->oneWaySync('ActionNetwork:Person:Object', $changedRemotePerson);

    $syncedContact = PersonMatchFixture::civiApi4GetSingleContactById($contactId);

    self::assertEquals(
      $changedFirstName,
      $syncedContact['first_name']);

    self::assertEquals(
      $originalRemotePerson->get('family_name'),
      $syncedContact['last_name']);
  }

  public function testSyncChangedNameOutgoing() {
    // SETUP

    [$originalRemotePerson, $contactId] =
      PersonMatchFixture::setUpExactlyOneMatchByEmailAndName(self::$remoteSystem);
    $this->createdRemotePeople[] = $originalRemotePerson;
    $this->createdContacts[] = $contactId;

    $originalFirstName = $originalRemotePerson->get('given_name');

    // TEST PROPER

    $changedFirstName = $originalFirstName . ' plus this';

    \Civi\Api4\Contact::update(FALSE)
      ->addWhere('id', '=', $contactId)
      ->addValue('first_name', $changedFirstName)
      ->execute();

    self::$syncer->oneWaySync('Local:Contact:Id', $contactId);

    $remotePeopleWithTheEmail = self::$remoteSystem->find(
      'osdi:people',
      [['email', 'eq', $originalRemotePerson->getEmailAddress()]]);
    $this->createdRemotePeople[] = $remotePeopleWithTheEmail->filteredFirst();

    self::assertEquals(
      $changedFirstName,
      $remotePeopleWithTheEmail->filteredFirst()->get('given_name'));

    self::assertEquals(
      $originalRemotePerson->get('family_name'),
      $remotePeopleWithTheEmail->filteredFirst()->get('family_name'));
  }

  public function testSyncAddedAddressIncoming() {
    // SETUP

    [$originalRemotePerson, $contactId] =
      PersonMatchFixture::setUpExactlyOneMatchByEmailAndName(self::$remoteSystem);
    $this->createdRemotePeople[] = $originalRemotePerson;
    $this->createdContacts[] = $contactId;

    $originalContact = PersonMatchFixture::civiApi4GetSingleContactById($contactId);
    $newStreet = '123 Test St.';

    // TEST PROPER

    $address = $originalRemotePerson->get('postal_addresses')[0];
    $address['address_lines'][0] = $newStreet;
    $changedRemotePerson = $originalRemotePerson;
    $changedRemotePerson->set('postal_addresses', [$address]);
    self::$remoteSystem->save($changedRemotePerson);

    self::assertNotEquals($newStreet,
      $originalContact['address.street_address'] ?? NULL);

    self::$syncer->oneWaySync('ActionNetwork:Person:Object', $changedRemotePerson);

    $syncedContact = PersonMatchFixture::civiApi4GetSingleContactById($contactId);

    self::assertEquals(
      $newStreet,
      $syncedContact['address.street_address']);
  }

  public function testSyncAddedAddressOutgoing() {
    // SETUP

    [$originalRemotePerson, $contactId] =
      PersonMatchFixture::setUpExactlyOneMatchByEmailAndName(self::$remoteSystem);
    $this->createdRemotePeople[] = $originalRemotePerson;
    $this->createdContacts[] = $contactId;

    $newStreet = '123 Test St.';

    // TEST PROPER

    $reFetchedRemotePerson = self::$remoteSystem
      ->fetchPersonById($originalRemotePerson->getId());

    self::assertNotEquals($newStreet,
      $reFetchedRemotePerson
        ->get('postal_addresses')[0]['address_lines'][0] ?? NULL);

    \Civi\Api4\Address::create()
      ->addValue('contact_id', $contactId)
      ->addValue('location_type_id', 1)
      ->addValue('is_primary', TRUE)
      ->addValue('street_address', $newStreet)
      ->addValue('postal_code', '65542')
      ->execute();

    self::$syncer->oneWaySync('Local:Contact:Id', $contactId);

    $reFetchedRemotePerson = self::$remoteSystem
      ->fetchPersonById($originalRemotePerson->getId());

    self::assertEquals(
      $newStreet,
      $reFetchedRemotePerson
        ->get('postal_addresses')[0]['address_lines'][0] ?? NULL);
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

    $existingMatchHistory = \Civi\Api4\OsdiMatch::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->execute();

    self::assertCount(0, $existingMatchHistory);

    // FIRST SYNC
    self::$syncer->oneWaySync('Local:Contact:Id', $contact['id']);

    $remotePeopleWithEmail1 = self::$remoteSystem->find(
      'osdi:people',
      [['email', 'eq', $emailOne]]);
    $originalPerson = $this->createdRemotePeople[] = $remotePeopleWithEmail1->filteredFirst();

    self::assertEquals($firstNameOne, $originalPerson->get('given_name'));

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
    self::$syncer->oneWaySync('Local:Contact:Id', $contact['id']);
    // the system sometimes is slow to catch up
    usleep(500000);

    $remotePeopleWithEmail2 = self::$remoteSystem->find(
      'osdi:people',
      [['email', 'eq', $emailTwo]]);
    $this->createdRemotePeople[] = $remotePeopleWithEmail2->filteredFirst();

    $changedPerson = self::$remoteSystem->fetchById(
      'osdi:people',
      $originalPerson->getId()
    );
    $changedPersonEmail = $changedPerson->getEmailAddress();
    $changedPersonName = $changedPerson->get('given_name');

    // CLEAN UP BEFORE ASSERTING

    $changedPerson->set('email_addresses', [['address' => $emailOne]]);
    self::$remoteSystem->save($changedPerson);

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

    $existingMatchHistory = \Civi\Api4\OsdiMatch::get(FALSE)
      ->addWhere('contact_id', '=', $contact['id'])
      ->execute();

    self::assertCount(0, $existingMatchHistory);

    // FIRST SYNC
    self::$syncer->oneWaySync('Local:Contact:Id', $contact['id']);

    $remotePeopleWithEmail1 = self::$remoteSystem->find(
      'osdi:people',
      [['email', 'eq', $emailOne]]);
    $originalPerson = $this->createdRemotePeople[] = $remotePeopleWithEmail1->filteredFirst();

    self::assertEquals($firstNameOne, $originalPerson->get('given_name'));

    // CONFLICTING PERSON

    $emailTwo = "different-$emailOne";
    $nameTwo = "Not $firstNameOne";

    $scratchPerson = new \Civi\Osdi\ActionNetwork\Object\Person();
    $scratchPerson->set('email_addresses', [['address' => $emailTwo]]);
    $scratchPerson->set('given_name', $nameTwo);
    $this->createdRemotePeople[] = self::$remoteSystem->save($scratchPerson);

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
    $result = self::$syncer->oneWaySync('Local:Contact:Id', $contact['id']);

    self::assertFalse($result);

    $changedPerson = self::$remoteSystem->fetchById(
      'osdi:people',
      $originalPerson->getId()
    );
    $changedPersonEmail = $changedPerson->getEmailAddress();
    $changedPersonName = $changedPerson->get('given_name');

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
