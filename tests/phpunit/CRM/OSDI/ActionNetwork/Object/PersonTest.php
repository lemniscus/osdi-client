<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class CRM_OSDI_ActionNetwork_Object_PersonTest extends \PHPUnit\Framework\TestCase implements
    HeadlessInterface,
    HookInterface,
    TransactionalInterface {

  public static \Civi\Osdi\ActionNetwork\RemoteSystem $system;

  /**
   * @var \Civi\Osdi\ActionNetwork\Object\Person[]
   */
  private $createdPeople = [];

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public static function setUpBeforeClass(): void {
    self::$system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
    parent::setUpBeforeClass();
  }

  public function setUp(): void {
    CRM_OSDI_FixtureHttpClient::resetHistory();
    parent::setUp();
  }

  public function tearDown(): void {
    while ($person = array_pop($this->createdPeople)) {
      $person->delete();
    }

    parent::tearDown();
  }

  public function makeFreshEmptyUnsavedPerson(): \Civi\Osdi\ActionNetwork\Object\Person {
    return new \Civi\Osdi\ActionNetwork\Object\Person(self::$system);
  }

  private function makeUnsavedPersonWithFirstLastEmailPhone(): \Civi\Osdi\ActionNetwork\Object\Person {
    $unsavedNewPerson = $this->makeFreshEmptyUnsavedPerson();
    $unsavedNewPerson->givenName->set('Testy');
    $unsavedNewPerson->familyName->set('McTest');
    $unsavedNewPerson->emailAddress->set('testy@test.net');
    $unsavedNewPerson->phoneNumber->set('12025551212');
    return $unsavedNewPerson;
  }

  public function testPersonCreate_Save_Fetch() {
    // CREATE
    $unsavedNewPerson = $this->makeUnsavedPersonWithFirstLastEmailPhone();

    $this->assertNull($unsavedNewPerson->getId());

    $this->createdPeople[] = $savedPerson = $unsavedNewPerson->save();
    $savedPersonId = $savedPerson->getId();

    $this->assertNotNull($savedPersonId);

    // READ
    $fetchedOsdiPerson = $this->makeFreshEmptyUnsavedPerson();
    $fetchedOsdiPerson->setId($savedPersonId);
    $fetchedOsdiPerson->load();

    $this->assertEquals($savedPerson->emailAddress->get(),
      $fetchedOsdiPerson->emailAddress->get());
  }

  public function testPersonWithoutIdHasIdAfterSaving() {
    // CREATE
    $person = $this->makeUnsavedPersonWithFirstLastEmailPhone();

    $this->assertNull($person->getId());

    $this->createdPeople[] = $person->save();

    $this->assertNotNull($person->getId());
  }

  public function testPersonCreate_Update_Fetch() {
    // CREATE
    $unsavedNewPerson = $this->makeUnsavedPersonWithFirstLastEmailPhone();
    $this->createdPeople[] = $savedPerson = $unsavedNewPerson->save();

    // UPDATE (SET)
    $newName = 'Mc' . $savedPerson->familyName->get();
    $savedPerson->familyName->set($newName);
    $reSavedOsdiPerson = $savedPerson->save();
    $this->assertEquals($newName, $reSavedOsdiPerson->familyName->get());
  }

  public function testPersonEmailCannotBeChangedIfNewOneIsAlreadyTaken() {
    // SAVE TWO PEOPLE WITH DIFFERENT EMAILS
    $abeUnsaved = $this->makeFreshEmptyUnsavedPerson();
    $abeUnsaved->emailAddress->set('abe@ab.ca');
    $abeUnsaved->givenName->set('Abe');
    $abeSaved = $this->createdPeople[] = $abeUnsaved->save();

    $zoeUnsaved = $this->makeFreshEmptyUnsavedPerson();
    $zoeUnsaved->emailAddress->set('zoe@zyz.xzy');
    $zoeUnsaved->givenName->set('Zoe');
    $zoeSaved = $this->createdPeople[] = $zoeUnsaved->save();

    // CHANGE EMAIL
    $zoeSaved->emailAddress->set('abe@ba.ca');
    $zoeSaved->givenName->set('Abe');
    $zoeChanged = $zoeSaved->save();

    self::assertNotEquals('abe@ba.ca',
      $zoeChanged->emailAddress->getOriginal(),
      'We did not expect email change to be successful');
    self::assertEquals('Abe',
      $zoeChanged->givenName->getOriginal(),
      'We expect name change to stick, even if email change is unsuccessful. Messy!');
  }

  public function testPersonEmailCanBeChangedIfNewOneIsNotAlreadyTaken() {
    // CREATE
    $draftPerson = $this->makeUnsavedPersonWithFirstLastEmailPhone();
    $this->createdPeople[] = $savedPerson = $draftPerson->save();
    $email1 = $savedPerson->emailAddress->get();
    $name1 = $savedPerson->givenName->get();

    self::assertNotNull($email1);

    $email2 = "yet-another-$email1";
    $name2 = "Different $name1";
    $searchResults = self::$system->find('osdi:people', [
      [
        'email',
        'eq',
        $email2,
      ],
    ]);

    $this->assertEquals(0,
      $searchResults->rawCurrentCount(),
      'the new email address should not already be in use');

    // CHANGE EMAIL
    $savedPerson->emailAddress->set($email2);
    $savedPerson->givenName->set($name2);
    $this->createdPeople[] = $changedPerson = $savedPerson->save();
    $changedPersonEmail = $changedPerson->emailAddress->getOriginal();
    $changedPersonName = $changedPerson->givenName->getOriginal();

    // CLEAN UP BEFORE ASSERTING
    $changedPerson->emailAddress->set($email1);
    $changedPerson->save();

    self::assertEquals($email2,
      $changedPersonEmail,
      'Email change should work because there is no address conflict');

    self::assertEquals($name2,
      $changedPersonName,
      'Name should have changed too');
  }

  public function testTrySaveSuccess() {
    $system = self::$system;
    $draftPerson = $this->makeUnsavedPersonWithFirstLastEmailPhone();
    $result = $system->trySave($draftPerson);
    $this->createdPeople[] = $savedPerson = $result->getReturnedObject();

    self::assertEquals(\Civi\Osdi\ActionNetwork\Object\Person::class,
      get_class($savedPerson));
    /** @var \Civi\Osdi\ActionNetwork\Object\Person $savedPerson */
    self::assertEquals(\Civi\Osdi\SaveResult::SUCCESS, $result->getStatus());
    self::assertEquals($draftPerson->emailAddress->get(),
      $savedPerson->emailAddress->get());
    self::assertEquals($draftPerson->phoneNumber->get(),
      $savedPerson->phoneNumber->get());
  }

  public function testTrySaveUnableToChangeEmail() {
    $system = self::$system;

    // CREATE
    $draftPerson = $this->makeUnsavedPersonWithFirstLastEmailPhone();
    $this->createdPeople[] = $savedPerson1 = $draftPerson->save();
    $email1 = $savedPerson1->emailAddress->get();
    $name1 = $savedPerson1->givenName->get();
    self::assertNotNull($email1);

    $draftPerson2 = $this->makeFreshEmptyUnsavedPerson();
    $email2 = "different-$email1";
    $name2 = "Different $name1";
    $draftPerson2->emailAddress->set($email2);
    $draftPerson2->givenName->set($name2);
    $savedPerson2 = $this->createdPeople[] = $draftPerson2->save();

    self::assertEquals($email2, $savedPerson2->emailAddress->getOriginal());

    // CHANGE EMAIL
    $savedPerson2->emailAddress->set($email1);
    $saveResult = $system->trySave($savedPerson2);

    self::assertEquals(\Civi\Osdi\SaveResult::ERROR, $saveResult->getStatus());
  }

  public function testPersonCreate_Append() {
    self::markTestSkipped('In Action Network, the only multivalue field is also '
    . 'subject to uniqueness constraints, so is difficult to test using mocks.');

    /*
    $system = self::$system;

    // CREATE
    $unsavedNewPerson = $this->makeUnsavedPersonWithFirstLastEmailPhone();
    $this->createdPeople[] = $savedPerson = $unsavedNewPerson->save();

    // UPDATE (APPEND)
    $savedPerson->appendTo('identifiers', 'donuts:yumyumyum');
    $reSavedOsdiPerson = $savedPerson->save();
    $this->assertContains(
    'donuts:yumyumyum',
    $reSavedOsdiPerson->identifiers->get());

    // TRY TO APPEND TO A NON-APPENDABLE FIELD
    $this->expectException(\Civi\Osdi\Exception\InvalidArgumentException::class);
    $this->expectExceptionMessage('Cannot append value to single-value field: "email_addresses"');
    $savedPerson->appendTo('email_addresses', [['address' => 'second@te.st']]);
     */
  }

  public function testPersonCreate_PseudoDelete() {
    // CREATE
    $unsavedPerson = $this->makeUnsavedPersonWithFirstLastEmailPhone();
    $savedPerson = $unsavedPerson->save();
    $savedPersonUrl = $savedPerson->getUrlForRead();
    $savedPersonEmail = $savedPerson->emailAddress->get();

    // DELETE
    $savedPerson->delete();
    $fetchedPerson = self::$system->fetchObjectByUrl('osdi:people', $savedPersonUrl);

    /** @var \Civi\Osdi\ActionNetwork\Object\Person $fetchedPerson */
    self::assertEquals('unsubscribed', $fetchedPerson->emailStatus->get());
    self::assertEquals('unsubscribed', $fetchedPerson->phoneStatus->get());
    self::assertEquals(NULL, $fetchedPerson->givenName->get());
    self::assertEquals(NULL, $fetchedPerson->familyName->get());
    self::assertEquals('en', $fetchedPerson->languageSpoken->get());
    self::assertEquals(NULL, $fetchedPerson->postalStreet->get());
    self::assertEquals(NULL, $fetchedPerson->postalLocality->get());
    self::assertEquals(NULL, $fetchedPerson->postalCode->get());
    self::assertEquals(NULL, $fetchedPerson->postalRegion->get());
    self::assertEquals('US', $fetchedPerson->postalCountry->get());

    $remotePeopleWithTheEmail = self::$system->find(
      'osdi:people',
      [['email', 'eq', $savedPersonEmail]]);

    self::assertEquals(0, $remotePeopleWithTheEmail->filteredCurrentCount());
  }

  public function testPersonCreate_FindByEmail() {
    // CREATE
    $unsavedNewPerson = $this->makeFreshEmptyUnsavedPerson();
    $unsavedNewPerson->emailAddress->set('testy@test.net');
    $this->createdPeople[] = $savedPerson = $unsavedNewPerson->save();
    $savedPersonId = $savedPerson->getId();

    // FIND
    $searchResults = self::$system->find('osdi:people', [
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
    // CREATE
    $unsavedRob = $this->makeFreshEmptyUnsavedPerson();
    $unsavedRob->givenName->set('Rob');
    $unsavedRob->emailAddress->set('rob@bobert.com');
    $savedRob = $unsavedRob->save();

    $unsavedRoberto = $this->makeFreshEmptyUnsavedPerson();
    $unsavedRoberto->givenName->set('Roberto');
    $unsavedRoberto->emailAddress->set('roberto.longname@muchlonger.com');
    $savedRoberto = $unsavedRoberto->save();

    self::assertEquals('Rob', $savedRob->givenName->getOriginal());
    self::assertEquals('Roberto', $savedRoberto->givenName->getOriginal());

    // FIND
    $searchResults = self::$system->find('osdi:people', [
      [
        'given_name',
        'eq',
        'Rob',
      ],
    ]);
    self::assertGreaterThan(0, $searchResults->rawCurrentCount());
    foreach ($searchResults->toArray() as $foundPerson) {
      $this->assertEquals('Rob', $foundPerson->givenName->get());
    }

    $searchResults = self::$system->find('osdi:people', [
      [
        'given_name',
        'eq',
        'Roberto',
      ],
    ]);
    self::assertGreaterThan(0, $searchResults->rawCurrentCount());
    foreach ($searchResults->toArray() as $foundPerson) {
      $this->assertEquals('Roberto', $foundPerson->givenName->get());
    }
  }

  public function testPersonCreate_FindByFirstAndLast() {
    $system = self::$system;

    // CREATE
    $unsavedNewPerson = $this->makeFreshEmptyUnsavedPerson();
    $unsavedNewPerson->emailAddress->set('for.finding.via.first.and.last.name@foo.com');
    $unsavedNewPerson->givenName->set('FindableFoo');
    $unsavedNewPerson->familyName->set('FindableBar');
    $this->createdPeople[] = $savedPerson = $unsavedNewPerson->save();
    $savedPersonId = $savedPerson->getId();
    
    // FIND
    $searchResults = $system->find('osdi:people',
      [
        ['given_name', 'eq', 'FindableFoo'],
        ['family_name', 'eq', 'FindableBar'],
      ]);
    $resultIds = array_map(
      function (\Civi\Osdi\ActionNetwork\Object\Person $foundPerson) {
        return $foundPerson->getId();
      },
      $searchResults->toArray());
    $this->assertContains($savedPersonId, $resultIds, print_r($resultIds, TRUE)
      . "\n(Is there a lag between writing to AN and finding the written data?)");
  }

  public function testPersonCreate_FindByDateModified() {
    $system = self::$system;

    // CREATE
    $unsavedNewPerson1 = $this->makeFreshEmptyUnsavedPerson();
    $unsavedNewPerson1->emailAddress->set('first@test.net');
    $unsavedNewPerson2 = $this->makeFreshEmptyUnsavedPerson();
    $unsavedNewPerson2->emailAddress->set('second@test.net');
    $unsavedNewPerson3 = $this->makeFreshEmptyUnsavedPerson();
    $unsavedNewPerson3->emailAddress->set('third@test.net');

    $savedPerson1 = $unsavedNewPerson1->save();
    $savedPerson1ModTime = $savedPerson1->modifiedDate->get();
    if (time() - strtotime($savedPerson1ModTime) < 2) {
      sleep(1);
    }

    $savedPerson2 = $unsavedNewPerson2->save();
    $savedPerson2ModTime = $savedPerson2->modifiedDate->get();
    if (time() - strtotime($savedPerson2ModTime < 2)) {
      sleep(1);
    }

    $savedPerson3 = $unsavedNewPerson3->save();
    $savedPerson3ModTime = $savedPerson3->modifiedDate->get();

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
        strtotime($foundPerson->modifiedDate->get()));
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
        strtotime($foundPerson->modifiedDate->get()));
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

}
