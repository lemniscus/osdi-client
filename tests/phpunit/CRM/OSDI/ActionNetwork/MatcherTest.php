<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use CRM_OSDI_Fixture_PersonMatching as F;
use Civi\Osdi\LocalObject\Person as LocalPerson;

/**
 * Test \Civi\Osdi\RemoteSystemInterface
 *
 * @group headless
 */
class CRM_OSDI_ActionNetwork_MatcherTest extends \PHPUnit\Framework\TestCase implements
    HeadlessInterface,
    TransactionalInterface {

  private \Civi\Osdi\ActionNetwork\RemoteSystem $system;

  private \Civi\Osdi\ActionNetwork\Matcher\OneToOneEmailOrFirstLastEmail $matcher;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public static function setUpBeforeClass(): void {
    CRM_OSDI_Fixture_PersonMatching::$personClass = \Civi\Osdi\ActionNetwork\Object\Person::class;
  }

  public function setUp(): void {
    $this->system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
    $this->matcher = $this->createMatcher($this->system);
    CRM_OSDI_FixtureHttpClient::resetHistory();
    parent::setUp();
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  private function createMatcher(\Civi\Osdi\RemoteSystemInterface $system
  ): \Civi\Osdi\ActionNetwork\Matcher\OneToOneEmailOrFirstLastEmail {
    $syncer = new \Civi\Osdi\ActionNetwork\Syncer\Person($system);
    $syncer->setSyncProfile(['mapper' => Civi\Osdi\ActionNetwork\Mapper\Person::class]);
    return new Civi\Osdi\ActionNetwork\Matcher\OneToOneEmailOrFirstLastEmail($syncer);
  }

  private function assertMatchResultIsNotError_NoMatch_ZeroCount(\Civi\Osdi\MatchResult $matchResult): void {
    $this->assertNull($matchResult->getMatch());
    $this->assertFalse($matchResult->isError());
    $this->assertEquals(\Civi\Osdi\MatchResult::NO_MATCH, $matchResult->getStatus());
  }

  public function testRemoteMatch_OneToOneEmailSuccess() {
    [$emailAddress, $remotePerson, $contactId] =
      F::setUpExactlyOneMatchByEmail_DifferentNames($this->system);
    $matchResult = $this->matcher->tryToFindMatchForLocalContact(new LocalPerson($contactId));
    $this->assertNotNull($matchResult->getMatch());
    $this->assertEquals($emailAddress, $matchResult->getRemoteObject()->getEmailAddress());
  }

  public function testRemoteMatch_NoMatchingEmail() {
    [$contactId, $remotePerson] =
      F::setUpLocalAndRemotePeople_SameName_DifferentEmail($this->system);
    $matchResult = $this->matcher->tryToFindMatchForLocalContact(new LocalPerson($contactId));
    $this->assertMatchResultIsNotError_NoMatch_ZeroCount($matchResult);
  }

  public function testRemoteMatch_BadContactId() {
    $contactId = 999999999;
    $contactsWithTheId = civicrm_api4(
      'Contact',
      'get',
      [
        'select' => ['row_count'],
        'where' => [['id', '=', $contactId]],
      ]
    );
    $this->assertEquals(0, $contactsWithTheId->count());

    $matchResult = $this->matcher->tryToFindMatchForLocalContact(new LocalPerson($contactId));
    $this->assertNull($matchResult->getMatch());
    $this->assertTrue($matchResult->isError());
    $this->assertEquals(\Civi\Osdi\MatchResult::ERROR_INVALID_ID, $matchResult->getStatus());
  }

  public function testRemoteMatch_NoEmail() {
    $unsavedRemotePerson = F::makeNewOsdiPersonWithFirstLastEmail();
    $this->system->save($unsavedRemotePerson);
    $contactArr = F::civiApi4CreateContact('Testy', 'McTest')->first();
    $contactId = $contactArr['id'];

    $matchResult = $this->matcher->tryToFindMatchForLocalContact(new LocalPerson($contactId));
    self::assertTrue($matchResult->isError());
    self::assertEquals($matchResult::ERROR_MISSING_DATA, $matchResult->getStatus());
    $this->assertNull($matchResult->getMatch());
  }

  public function testRemoteMatch_EmailIndeterminate_FirstLastSuccess() {
    [$remotePerson, $idOfMatchingContact, $idOf_Non_MatchingContact] =
      F::setUpRemotePerson_TwoLocalContactsMatchingByEmail_OneAlsoMatchingByName($this->system);
    $matchingContact = F::civiApi4GetSingleContactById($idOfMatchingContact);

    $matchResult1 = $this->matcher->tryToFindMatchForLocalContact(new LocalPerson($idOfMatchingContact));
    $this->assertNotNull($matchResult1->getMatch());
    $this->assertEquals($matchingContact['email.email'],
      $matchResult1->getMatch()->getEmailAddress());
    $this->assertEquals($matchingContact['first_name'],
      $matchResult1->getMatch()->get('given_name'));
    $this->assertEquals($matchingContact['last_name'],
      $matchResult1->getMatch()->get('family_name'));

    $matchResult2 = $this->matcher->tryToFindMatchForLocalContact(new LocalPerson($idOf_Non_MatchingContact));
    self::assertTrue($matchResult2->isError());
    self::assertEquals($matchResult2::ERROR_INDETERMINATE, $matchResult2->getStatus());
    self::assertNull($matchResult2->getMatch());
  }

  public function testRemoteMatch_EmailIndeterminate_NoMatchingFirstLast() {
    [$remotePerson, $idsOfContactsWithSameEmailAndDifferentName] =
      F::setUpRemotePerson_TwoLocalContactsMatchingByEmail_NeitherMatchingByName($this->system);

    foreach ($idsOfContactsWithSameEmailAndDifferentName as $id) {
      $matchResult = $this->matcher->tryToFindMatchForLocalContact(new LocalPerson($id));
      self::assertTrue($matchResult->isError());
      self::assertEquals($matchResult::ERROR_INDETERMINATE, $matchResult->getStatus());
      $this->assertNull($matchResult->getMatch());
    }
  }

  public function testLocalMatch_OneToOneEmailSuccess() {
    [$emailAddress, $remotePerson, $contactId] =
      F::setUpExactlyOneMatchByEmail_DifferentNames($this->system);
    $matchResult = $this->matcher->tryToFindMatchForRemotePerson($remotePerson);
    $this->assertNotNull($matchResult->getMatch());
    $this->assertEquals($emailAddress, $matchResult->getLocalObject()->loadOnce()->emailEmail->get());
  }

  public function testLocalMatch_NoMatchingEmail() {
    [$contactId, $remotePerson] =
      F::setUpLocalAndRemotePeople_SameName_DifferentEmail($this->system);
    $matchResult = $this->matcher->tryToFindMatchForRemotePerson($remotePerson);
    $this->assertMatchResultIsNotError_NoMatch_ZeroCount($matchResult);
  }

  public function testLocalMatch_EmailIndeterminate_FirstLastSuccess() {
    [$remotePerson, $idOfMatchingContact, $idOf_Non_MatchingContact] =
      F::setUpRemotePerson_TwoLocalContactsMatchingByEmail_OneAlsoMatchingByName($this->system);

    $matchResult = $this->matcher->tryToFindMatchForRemotePerson($remotePerson);
    $this->assertNotNull($matchResult->getMatch());
    $this->assertEquals($idOfMatchingContact,
      $matchResult->getMatch()->getId());

    $matchResult->getMatch()->loadOnce();

    $this->assertEquals($remotePerson->getEmailAddress(),
      $matchResult->getMatch()->emailEmail->get());
    $this->assertEquals($remotePerson->get('given_name'),
      $matchResult->getMatch()->firstName->get());
    $this->assertEquals($remotePerson->get('family_name'),
      $matchResult->getMatch()->lastName->get());
  }

  public function testLocalMatch_EmailIndeterminate_NoMatchingFirstLast() {
    [$remotePerson, $idsOfContactsWithSameEmailAndDifferentName] =
      F::setUpRemotePerson_TwoLocalContactsMatchingByEmail_NeitherMatchingByName($this->system);

    $matchResult = $this->matcher->tryToFindMatchForRemotePerson($remotePerson);
    self::assertTrue($matchResult->isError());
    $this->assertNull($matchResult->getMatch());
  }

  public function testLocalMatch_EmailIndeterminate_FirstLastIndeterminate() {
    [$remotePerson, $idsOfContactsWithSameEmailAndDifferentName] =
      F::setUpRemotePerson_TwoLocalContactsMatchingByEmail_BothMatchingByName($this->system);

    $matchResult = $this->matcher->tryToFindMatchForRemotePerson($remotePerson);
    self::assertTrue($matchResult->isError());
    self::assertNull($matchResult->getMatch());
  }
}
