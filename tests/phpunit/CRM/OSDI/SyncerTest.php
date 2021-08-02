<?php

use Civi\Osdi\ActionNetwork\OsdiPerson as ANPerson;
use CRM_OSDI_Fixture_PersonMatching as PersonMatchFixture;

/**
 * @group headless
 */
class CRM_OSDI_SyncerTest extends PHPUnit\Framework\TestCase implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\TransactionalInterface {

  /**
   * @var array
   */
  private static $syncProfile;

  /**
   * @var \Civi\Api4\OsdiSyncer
   */
  private static $syncer;

  /**
   * @var \Civi\Osdi\ActionNetwork\RemoteSystem
   */
  private static $remoteSystem;

  /**
   * @var array
   */
  private $createdRemotePeople = [];

  /**
   * @var array
   */
  private $createdContacts = [];

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public static function setUpBeforeClass(): void {
    PersonMatchFixture::$personClass = Civi\Osdi\ActionNetwork\OsdiPerson::class;

    self::$syncProfile = \Civi\Api4\OsdiSyncProfile::create(FALSE)
      ->addValue('is_default', TRUE)
      ->addValue('remote_system', 'Civi\Osdi\ActionNetwork\RemoteSystem')
      ->addValue('entry_point', 'http://foo')
      ->addValue(
        'matcher',
        \Civi\Osdi\ActionNetwork\Matcher\OneToOneEmailOrFirstLastEmail::class)
      ->addValue(
        'mapper',
        \Civi\Osdi\ActionNetwork\Mapper\Example::class)
      ->execute()->single();

    self::$syncer = new \Civi\Osdi\Syncer();

    self::$remoteSystem = CRM_OSDI_ActionNetwork_MatcherTest::createRemoteSystem();

    self::$syncer->setRemoteSystem(self::$remoteSystem);

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

  public function testNewSyncerUsesDefaultProfile() {
    $syncer = new \Civi\Osdi\Syncer();
    $syncProfile = $syncer->getSyncProfile();
    self::assertIsArray($syncProfile);
    self::assertNotEmpty($syncProfile['id']);
    self::assertTrue($syncProfile['is_default']);
  }

  public function testNewSyncerUsesDefaultRemoteSystem() {
    $syncer = new \Civi\Osdi\Syncer();
    $system = $syncer->getRemoteSystem();
    self::assertContains(\Civi\Osdi\RemoteSystemInterface::class, class_implements($system));
    self::assertNotEmpty($system->getEntryPoint());
  }

  public function testRemoteSystemIsSettable() {
    $syncer = new \Civi\Osdi\Syncer();
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
    $remotePerson = new \Civi\Osdi\Generic\OsdiPerson();
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

    self::$syncer->syncRemotePerson($person);

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

    if ($remotePeopleWithTheEmail->currentCount()) {
      self::$remoteSystem->delete($remotePeopleWithTheEmail->first());
      $remotePeopleWithTheEmail = self::$remoteSystem->find(
        'osdi:people',
        [['email', 'eq', $emailAddress]]);
    }

    self::assertEquals(0, $remotePeopleWithTheEmail->currentCount());

    // TEST PROPER

    self::$syncer->syncContact($contact['id']);

    $remotePeopleWithTheEmail = self::$remoteSystem->find(
      'osdi:people',
      [['email', 'eq', $emailAddress]]);
    $this->createdRemotePeople[] = $remotePeopleWithTheEmail->first();

    self::assertEquals(1, $remotePeopleWithTheEmail->currentCount());

    self::assertEquals(
      $firstName,
      $remotePeopleWithTheEmail->first()->get('given_name'));

    self::assertEquals(
      $lastName,
      $remotePeopleWithTheEmail->first()->get('family_name'));
  }

  public function testSyncChangedNameOutgoing() {
    // SETUP

    [$originalRemotePerson, $contactId] =
      PersonMatchFixture::setUpExactlyOneMatchByEmailAndName(self::$remoteSystem);
    $this->createdRemotePeople[] = $originalRemotePerson;
    $this->createdContacts[] = $contactId;

    $changedFirstName = $originalRemotePerson->get('given_name') . ' plus this';

    \Civi\Api4\Contact::update(FALSE)
      ->addWhere('id', '=', $contactId)
      ->addValue('first_name', $changedFirstName)
      ->execute();

    // TEST PROPER

    self::$syncer->syncContact($contactId);

    $remotePeopleWithTheEmail = self::$remoteSystem->find(
      'osdi:people',
      [['email', 'eq', $originalRemotePerson->getEmailAddress()]]);
    $this->createdRemotePeople[] = $remotePeopleWithTheEmail->first();

    self::assertEquals(
      $changedFirstName,
      $remotePeopleWithTheEmail->first()->get('given_name'));

    self::assertEquals(
      $originalRemotePerson->get('family_name'),
      $remotePeopleWithTheEmail->first()->get('family_name'));
  }

  public function testSyncAddedAddressOutgoing() {
    // SETUP

    [$originalRemotePerson, $contactId] =
      PersonMatchFixture::setUpExactlyOneMatchByEmailAndName(self::$remoteSystem);
    $this->createdRemotePeople[] = $originalRemotePerson;
    $this->createdContacts[] = $contactId;

    $newStreet = '123 Test St.';

    self::assertNotEquals($newStreet,
      $originalRemotePerson->get('postal_addresses')[0]['address_lines'][0] ?? NULL);

    \Civi\Api4\Address::create()
      ->addValue('contact_id', $contactId)
      ->addValue('location_type_id', 1)
      ->addValue('is_primary', TRUE)
      ->addValue('street_address', $newStreet)
      ->addValue('postal_code', '65542')
      ->execute();

    // TEST PROPER

    self::$syncer->syncContact($contactId);

    $reFetchedRemotePerson = self::$remoteSystem
      ->fetchPersonById($originalRemotePerson->getId());
    self::assertEquals(
      $newStreet,
      $reFetchedRemotePerson
        ->get('postal_addresses')[0]['address_lines'][0]);
  }

}
