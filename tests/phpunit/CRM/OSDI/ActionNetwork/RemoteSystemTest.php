<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test \Civi\Osdi\ActionNetwork\RemoteSystem
 *
 * @group headless
 */
class CRM_OSDI_ActionNetwork_RemoteSystemTest extends \PHPUnit\Framework\TestCase implements
    HeadlessInterface,
    HookInterface,
    TransactionalInterface {

  /**
   * @var \Civi\Osdi\ActionNetwork\Object\Person[]
   */
  private $createdPeople = [];

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    CRM_OSDI_FixtureHttpClient::resetHistory();
    parent::setUp();
  }

  public function tearDown(): void {
    $system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
    while ($person = array_pop($this->createdPeople)) {
      $system->delete($person);
    }

    parent::tearDown();
  }

  public function makeBlankOsdiPerson(): \Civi\Osdi\ActionNetwork\Object\Person {
    return new \Civi\Osdi\ActionNetwork\Object\Person();
  }

  /**
   * @return \Civi\Osdi\ActionNetwork\Object\Person
   * @throws \Civi\Osdi\Exception\InvalidArgumentException
   */
  private function makeNewOsdiPersonWithFirstLastEmailPhone(): \Civi\Osdi\ActionNetwork\Object\Person {
    $unsavedNewPerson = $this->makeBlankOsdiPerson();
    $unsavedNewPerson->set('given_name', 'Testy');
    $unsavedNewPerson->set('family_name', 'McTest');
    $unsavedNewPerson->set('email_addresses', [['address' => 'testy@test.net']]);
    $unsavedNewPerson->set('phone_numbers', [['number' => '12025551212']]);
    return $unsavedNewPerson;
  }

  public function expected($key): string {
    $expected = [];
    if (array_key_exists($key, $expected)) {
      return $expected[$key];
    }
    $remotePersonTest = new CRM_OSDI_ActionNetwork_OsdiPersonTest();
    return $remotePersonTest->expected($key);
  }

  public function testPersonCreate_Fetch() {
    $system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    // CREATE
    $unsavedNewPerson = $this->makeNewOsdiPersonWithFirstLastEmailPhone();
    $this->assertNull($unsavedNewPerson->getId());
    $this->createdPeople[] = $savedPerson = $system->save($unsavedNewPerson);
    $savedPersonId = $savedPerson->getId();
    $this->assertNotNull($savedPersonId);

    // READ
    $fetchedOsdiPerson = $system->fetchPersonById($savedPersonId);
    $fetchedEmailAddresses = $fetchedOsdiPerson->get('email_addresses');
    $this->assertEquals('testy@test.net', $fetchedEmailAddresses[0]['address']);
  }

  public function testPersonCreate_Set() {
    $system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    // CREATE
    $unsavedNewPerson = $this->makeNewOsdiPersonWithFirstLastEmailPhone();
    $this->createdPeople[] = $savedPerson = $system->save($unsavedNewPerson);

    // UPDATE (SET)
    $savedPerson->set('family_name', 'Testerson');
    $reSavedOsdiPerson = $system->save($savedPerson);
    $this->assertEquals(
      'Testerson', $reSavedOsdiPerson->get('family_name'));

    // clean up
    $system->save($unsavedNewPerson);
  }

  public function testPersonEmailCannotBeChangedIfNewOneIsAlreadyTaken() {
    $system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    // CREATE
    $draftPerson = $this->makeNewOsdiPersonWithFirstLastEmailPhone();
    $this->createdPeople[] = $savedPerson1 = $system->save($draftPerson);
    $email1 = $savedPerson1->getEmailAddress();
    $name1 = $savedPerson1->get('given_name');
    self::assertNotNull($email1);

    $email2 = "different-$email1";
    $name2 = "Different $name1";
    $draftPerson->set('email_addresses', [['address' => $email2]]);
    $draftPerson->set('given_name', $name2);
    $this->createdPeople[] = $savedPerson2 = $system->save($draftPerson);

    // CHANGE EMAIL
    $savedPerson2->set('email_addresses', [['address' => $email1]]);
    $changedPerson2 = $system->save($savedPerson2);
    self::assertEquals($email2,
      $changedPerson2->getEmailAddress(),
      'Email change should not have been successful');
    self::assertEquals($name2,
      $changedPerson2->get('given_name'),
      'Name should not have changed either');
  }

  public function testPersonEmailCanBeChangedIfNewOneIsNotAlreadyTaken() {
    $system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    // CREATE
    $draftPerson = $this->makeNewOsdiPersonWithFirstLastEmailPhone();
    $this->createdPeople[] = $savedPerson = $system->save($draftPerson);
    $email1 = $savedPerson->getEmailAddress();
    $name1 = $savedPerson->get('given_name');
    self::assertNotNull($email1);

    $email2 = "yet-another-$email1";
    $name2 = "Different $name1";

    $searchResults = $system->find('osdi:people', [
      [
        'email',
        'eq',
        $email2,
      ],
    ]);
    $this->assertEquals(0,
      $searchResults->filteredCurrentCount(),
      'the new email address should not already be in use');


    // CHANGE EMAIL
    $savedPerson->set('email_addresses', [['address' => $email2]]);
    $savedPerson->set('given_name', $name2);
    $this->createdPeople[] = $changedPerson = $system->save($savedPerson);
    $changedPersonEmail = $changedPerson->getEmailAddress();
    $changedPersonName = $changedPerson->get('given_name');

    // CLEAN UP BEFORE ASSERTING
    $changedPerson->set('email_addresses', [['address' => $email1]]);
    $system->save($changedPerson);

    self::assertEquals($email2,
      $changedPersonEmail,
      'Email change should work because there is no address conflict');

    self::assertEquals($name2,
      $changedPersonName,
      'Name should have changed too');

  }

  public function testTrySaveSuccess() {
    $system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
    $draftPerson = $this->makeNewOsdiPersonWithFirstLastEmailPhone();
    $result = $system->trySave($draftPerson);
    $this->createdPeople[] = $savedPerson = $result->object();
    self::assertEquals(\Civi\Osdi\SaveResult::SUCCESS, $result->status());
    self::assertEquals($draftPerson->getEmailAddress(),
      $savedPerson->getEmailAddress());
    self::assertEquals($draftPerson->get('phone_numbers')[0]['number'],
      $savedPerson->get('phone_numbers')[0]['number']);
  }

  public function testTrySaveUnableToChangeEmail() {
    $system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    // CREATE
    $draftPerson = $this->makeNewOsdiPersonWithFirstLastEmailPhone();
    $this->createdPeople[] = $savedPerson1 = $system->save($draftPerson);
    $email1 = $savedPerson1->getEmailAddress();
    $name1 = $savedPerson1->get('given_name');
    self::assertNotNull($email1);

    $email2 = "different-$email1";
    $name2 = "Different $name1";
    $draftPerson->set('email_addresses', [['address' => $email2]]);
    $draftPerson->set('given_name', $name2);
    $this->createdPeople[] = $savedPerson2 = $system->save($draftPerson);
    self::assertEquals($email2, $savedPerson2->getEmailAddress());

    // CHANGE EMAIL
    $savedPerson2->set('email_addresses', [['address' => $email1]]);
    $saveResult = $system->trySave($savedPerson2);

    self::assertEquals(\Civi\Osdi\SaveResult::ERROR, $saveResult->status());
  }

  public function testPersonCreate_Append() {
    self::markTestSkipped('In Action Network, the only multivalue field is also '
    . 'subject to uniqueness constraints, so is difficult to test using mocks.');

    $system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    // CREATE
    $unsavedNewPerson = $this->makeNewOsdiPersonWithFirstLastEmailPhone();
    $this->createdPeople[] = $savedPerson = $system->save($unsavedNewPerson);

    // UPDATE (APPEND)
    $savedPerson->appendTo('identifiers', 'donuts:yumyumyum');
    $reSavedOsdiPerson = $system->save($savedPerson);
    $this->assertContains(
      'donuts:yumyumyum',
      $reSavedOsdiPerson->get('identifiers'));

    // TRY TO APPEND TO A NON-APPENDABLE FIELD
    $this->expectException(\Civi\Osdi\Exception\InvalidArgumentException::class);
    $this->expectExceptionMessage('Cannot append value to single-value field: "email_addresses"');
    $savedPerson->appendTo('email_addresses', [['address' => 'second@te.st']]);
  }

  public function testPersonCreate_PseudoDelete() {
    $system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    // CREATE
    $unsavedPerson = $this->makeNewOsdiPersonWithFirstLastEmailPhone();
    /** @var \Civi\Osdi\ActionNetwork\Object\Person $savedPerson */
    $savedPerson = $system->save($unsavedPerson);
    $savedPersonUrl = $savedPerson->getOwnUrl($system);
    $savedPersonEmail = $savedPerson->getEmailAddress();

    // DELETE
    $system->delete($savedPerson);
    $fetchedPerson = $system->fetchObjectByUrl('osdi:people', $savedPersonUrl);
    $fieldDefaults = [
      ['given_name', NULL],
      ['family_name', NULL],
      ['languages_spoken', ['en']],
      ['postal_addresses', 0, 'address_lines', 0, NULL],
      ['email_addresses', 0, 'status', 'unsubscribed'],
      ['email_addresses', 0, 'address', $savedPersonEmail],
      ['phone_numbers', 0, 'status', 'unsubscribed'],
      ['phone_numbers', 0, 'number', $savedPerson->get('phone_numbers')[0]['number']],
    ];
    foreach ($fieldDefaults as $x) {
      $fieldName = array_shift($x);
      $defaultValue = array_pop($x);
      self::assertEquals(
        $defaultValue,
        \CRM_Utils_Array::pathGet($fetchedPerson->get($fieldName), $x),
        "$fieldName: " . var_export($fetchedPerson->get($fieldName), TRUE)
      );
    }

    $remotePeopleWithTheEmail = $system->find(
      'osdi:people',
      [['email', 'eq', $savedPersonEmail]]);

    self::assertEquals(0, $remotePeopleWithTheEmail->filteredCurrentCount());
  }

  public function testPersonCreate_FindByEmail() {
    $system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    // CREATE
    $unsavedNewPerson = $this->makeBlankOsdiPerson();
    $unsavedNewPerson->set('email_addresses', [['address' => 'testy@test.net']]);
    $this->createdPeople[] = $savedPerson = $system->save($unsavedNewPerson);
    $savedPersonId = $savedPerson->getId();

    // FIND
    $searchResults = $system->find('osdi:people', [
      [
        'email',
        'eq',
        'testy@test.net',
      ],
    ]);
    $resultIds = array_map(
      function (\Civi\Osdi\ActionNetwork\Object\Person $foundPerson) {
        return $foundPerson->getId();
      },
      $searchResults->toArray());
    $this->assertContains($savedPersonId, $resultIds);
  }

  public function testPersonFindByExactStringReturnsExactMatches() {
    $system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    // CREATE
    $unsavedNewPerson = $this->makeNewOsdiPersonWithFirstLastEmailPhone();
    $this->createdPeople[] = $savedPerson1 = $system->save($unsavedNewPerson);

    $otherEmail = 'second-' . $savedPerson1->getEmailAddress();
    $familyName = $savedPerson1->get('family_name');
    $abbreviatedFamilyName = substr($familyName, 0, 4);
    $this->assertNotEquals($abbreviatedFamilyName, $familyName);

    $unsavedNewPerson->set('family_name', $abbreviatedFamilyName);
    $unsavedNewPerson->set('email_addresses', [['address' => $otherEmail]]);
    $this->createdPeople[] = $savedPerson2 = $system->save($unsavedNewPerson);

    // FIND
    $searchResults = $system->find('osdi:people', [
      [
        'family_name',
        'eq',
        $familyName,
      ],
    ]);
    self::assertGreaterThan(0, $searchResults->filteredCurrentCount());
    foreach ($searchResults->toArray() as $foundPerson) {
      $this->assertEquals($familyName, $foundPerson->get('family_name'));
    }

    $searchResults = $system->find('osdi:people', [
      [
        'family_name',
        'eq',
        $abbreviatedFamilyName,
      ],
    ]);
    self::assertGreaterThan(0, $searchResults->filteredCurrentCount());
    foreach ($searchResults->toArray() as $foundPerson) {
      $this->assertEquals($abbreviatedFamilyName, $foundPerson->get('family_name'));
    }
  }

  public function testPersonCreate_FindByFirstAndLast() {
    $system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    // CREATE
    $unsavedNewPerson = $this->makeNewOsdiPersonWithFirstLastEmailPhone();
    $this->createdPeople[] = $savedPerson = $system->save($unsavedNewPerson);
    $savedPersonId = $savedPerson->getId();
    $givenName = $savedPerson->get('given_name');
    $familyName = $savedPerson->get('family_name');

    // FIND
    $searchResults = $system->find('osdi:people',
      [
        ['given_name', 'eq', $givenName],
        ['family_name', 'eq', $familyName],
      ]);
    $resultIds = array_map(
      function (\Civi\Osdi\ActionNetwork\Object\Person $foundPerson) {
        return $foundPerson->getId();
      },
      $searchResults->toArray());
    $this->assertContains($savedPersonId, $resultIds, print_r($resultIds, TRUE));
  }

  public function testPersonCreate_FindByDateModified() {
    $system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    // CREATE
    $unsavedNewPerson1 = $this->makeBlankOsdiPerson();
    $unsavedNewPerson1->set('email_addresses', [['address' => 'first@test.net']]);
    $unsavedNewPerson2 = $this->makeBlankOsdiPerson();
    $unsavedNewPerson2->set('email_addresses', [['address' => 'second@test.net']]);
    $unsavedNewPerson3 = $this->makeBlankOsdiPerson();
    $unsavedNewPerson3->set('email_addresses', [['address' => 'third@test.net']]);

    $savedPerson1 = $system->save($unsavedNewPerson1);
    $savedPerson1ModTime = $savedPerson1->get('modified_date');
    if (time() - strtotime($savedPerson1ModTime) < 2) {
      sleep(1);
    }

    $savedPerson2 = $system->save($unsavedNewPerson2);
    $savedPerson2ModTime = $savedPerson2->get('modified_date');
    if (time() - strtotime($savedPerson2ModTime < 2)) {
      sleep(1);
    }

    $savedPerson3 = $system->save($unsavedNewPerson3);
    $savedPerson3ModTime = $savedPerson3->get('modified_date');

    // FIND
    $searchResults = $system->find('osdi:people', [
      [
        'modified_date',
        'lt',
        $savedPerson2ModTime,
      ],
    ]);
    /** @var \Civi\Osdi\ActionNetwork\Object\Person $foundPerson */
    foreach ($searchResults->toArray() as $foundPerson) {
      $this->assertLessThan(
        strtotime($savedPerson2ModTime),
        strtotime($foundPerson->get('modified_date')));
      $resultIds[] = $foundPerson->getId();
    }
    $this->assertContains($savedPerson1->getId(), $resultIds);

    $searchResults = $system->find('osdi:people', [
      [
        'modified_date',
        'gt',
        $savedPerson2ModTime,
      ],
    ]);
    foreach ($searchResults->toArray() as $foundPerson) {
      $this->assertGreaterThan(
        strtotime($savedPerson2ModTime),
        strtotime($foundPerson->get('modified_date')));
      $resultIds[] = $foundPerson->getId();
    }
    $this->assertContains($savedPerson3->getId(), $resultIds);

    $searchResults = $system->find('osdi:people', [
      ['modified_date', 'gt', $savedPerson1ModTime],
      ['modified_date', 'lt', $savedPerson3ModTime],
    ]);
    foreach ($searchResults->toArray() as $foundPerson) {
      $resultIds[] = $foundPerson->getId();
    }
    $this->assertContains($savedPerson2->getId(), $resultIds);
  }

  public function testFactoryMake_Tag() {
    $system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
    $osdiTag = $system->makeOsdiObject('osdi:tags');
    $osdiTag->set('name', 'test');
    $this->assertEquals('test', $osdiTag->getAltered('name'));
  }

  public function testTagCreate_Fetch() {
    $system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    // CREATE
    $unsavedTag = $system->makeOsdiObject('osdi:tags');
    $unsavedTag->set('name', 'Tagalina');
    $this->assertNull($unsavedTag->getId());
    $savedTag = $system->save($unsavedTag);
    $savedTagId = $savedTag->getId();
    $this->assertNotNull($savedTagId);

    // READ
    $fetchedOsdiTag = $system->fetchById('osdi:tags', $savedTagId);
    $this->assertEquals('Tagalina', $fetchedOsdiTag->get('name'));
  }

  public function testTaggingCreate_FetchComponents() {
    $system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    // CREATE
    $unsavedTag = $system->makeOsdiObject('osdi:tags');
    $unsavedTag->set('name', 'Tagalina');
    $savedTag = $system->save($unsavedTag);

    $unsavedPerson = $this->makeNewOsdiPersonWithFirstLastEmailPhone();
    /** @var \Civi\Osdi\ActionNetwork\Object\Person $savedPerson */
    $savedPerson = $system->save($unsavedPerson);

    /** @var \Civi\Osdi\ActionNetwork\Object\Tagging $unsavedTagging */
    $unsavedTagging = $system->makeOsdiObject('osdi:taggings');
    $unsavedTagging->setTag($savedTag, $system);
    $unsavedTagging->setPerson($savedPerson, $system);
    $savedTagging = $system->save($unsavedTagging);

    // FETCH COMPONENTS

    $this->assertEquals($savedTag->getId(), $savedTagging->getTag()->getId());
    $this->assertEquals($savedPerson->getId(), $savedTagging->getPerson()
      ->getId());
  }

  public function testTaggingCreate_Delete() {
    $system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    // CREATE
    $unsavedTag = $system->makeOsdiObject('osdi:tags');
    $unsavedTag->set('name', 'Tagalina');
    $savedTag = $system->save($unsavedTag);

    $unsavedPerson = $this->makeNewOsdiPersonWithFirstLastEmailPhone();
    /** @var \Civi\Osdi\ActionNetwork\Object\Person $savedPerson */
    $savedPerson = $system->save($unsavedPerson);

    /** @var \Civi\Osdi\ActionNetwork\Object\Tagging $unsavedTagging */
    $unsavedTagging = $system->makeOsdiObject('osdi:taggings');
    $unsavedTagging->setTag($savedTag, $system);
    $unsavedTagging->setPerson($savedPerson, $system);
    $savedTagging = $system->save($unsavedTagging);
    $savedTaggingUrl = $savedTagging->getOwnUrl($system);
    $this->assertStringStartsWith('http', $savedTaggingUrl);

    // DELETE
    $system->delete($savedTagging);
    $this->expectException(\Civi\Osdi\Exception\EmptyResultException::class);
    $system->fetchObjectByUrl('osdi:taggings', $savedTaggingUrl);
  }

}
