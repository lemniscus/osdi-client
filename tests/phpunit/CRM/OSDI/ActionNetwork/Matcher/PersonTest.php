<?php

use Civi\Osdi\LocalObject\Person as LocalPerson;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use CRM_OSDI_Fixture_PersonMatching as F;

/**
 * Test \Civi\Osdi\RemoteSystemInterface
 *
 * @group headless
 */
class CRM_OSDI_ActionNetwork_Matcher_PersonTest extends \PHPUnit\Framework\TestCase implements
    HeadlessInterface,
    TransactionalInterface {

  private \Civi\Osdi\ActionNetwork\RemoteSystem $system;

  private \Civi\Osdi\ActionNetwork\Matcher\Person\OneToOneEmailOrFirstLastEmail $matcher;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public static function setUpBeforeClass(): void {
    CRM_OSDI_Fixture_PersonMatching::$personClass = \Civi\Osdi\ActionNetwork\Object\Person::class;
  }

  public function setUp(): void {
    $this->system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
    CRM_OSDI_Fixture_PersonMatching::$remoteSystem = $this->system;
    $this->matcher = $this->createMatcher($this->system);
    CRM_OSDI_FixtureHttpClient::resetHistory();
    parent::setUp();
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  private function createMatcher(\Civi\Osdi\RemoteSystemInterface $system
  ): \Civi\Osdi\ActionNetwork\Matcher\Person\OneToOneEmailOrFirstLastEmail {
    return new \Civi\Osdi\ActionNetwork\Matcher\Person\OneToOneEmailOrFirstLastEmail($system, NULL);
  }

  /**
   * @param int|\Civi\Osdi\ActionNetwork\Object\Person $input
   *
   * @return \Civi\Osdi\LocalRemotePair
   */
  private static function makePair($input): \Civi\Osdi\LocalRemotePair {
    $pair = new \Civi\Osdi\LocalRemotePair();
    $pair->setLocalClass(LocalPerson::class);
    $pair->setRemoteClass(\Civi\Osdi\ActionNetwork\Object\Person::class);
    if (is_object($input)) {
      $pair->setRemoteObject($input)->setOrigin($pair::ORIGIN_REMOTE);
    }
    else {
      $localPerson = LocalPerson::fromId($input);
      $pair->setLocalObject($localPerson)->setOrigin($pair::ORIGIN_LOCAL);
    }
    return $pair;
  }

  private function assertMatchResultIsNotError_NoMatch_ZeroCount(\Civi\Osdi\Result\Match $matchResult): void {
    $this->assertNull($matchResult->getMatch());
    $this->assertFalse($matchResult->isError());
    $this->assertEquals(\Civi\Osdi\Result\Match::NO_MATCH, $matchResult->getStatusCode());
  }

  public function testRemoteMatch_OneToOneEmailSuccess() {
    [$emailAddress, $remotePerson, $contactId] =
      F::setUpExactlyOneMatchByEmail_DifferentNames();
    $matchResult = $this->matcher->tryToFindMatchForLocalObject(
      self::makePair($contactId));
    $this->assertNotNull($matchResult->getMatch());
    $this->assertEquals($emailAddress, $matchResult->getRemoteObject()->emailAddress->get());
  }

  public function testRemoteMatch_NoMatchingEmail() {
    [$contactId, $remotePerson] =
      F::setUpLocalAndRemotePeople_SameName_DifferentEmail($this->system);
    $matchResult = $this->matcher->tryToFindMatchForLocalObject(self::makePair($contactId));
    $this->assertMatchResultIsNotError_NoMatch_ZeroCount($matchResult);
  }

/*  public function testRemoteMatch_BadContactId() {
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

    $matchResult = $this->matcher->tryToFindMatchForLocalObject(self::makePair($contactId));
    $this->assertNull($matchResult->getMatch());
    $this->assertTrue($matchResult->isError());
    $this->assertEquals(\Civi\Osdi\Result\Match::ERROR_INVALID_ID, $matchResult->getStatusCode());
  }*/

  public function testRemoteMatch_NoEmail() {
    $unsavedRemotePerson = F::makeNewOsdiPersonWithFirstLastEmail();
    $unsavedRemotePerson->save();
    $contactArr = F::civiApi4CreateContact('Testy', 'McTest')->first();
    $contactId = $contactArr['id'];

    $matchResult = $this->matcher->tryToFindMatchForLocalObject(self::makePair($contactId));
    self::assertTrue($matchResult->isError());
    self::assertEquals($matchResult::ERROR_MISSING_DATA, $matchResult->getStatusCode());
    $this->assertNull($matchResult->getMatch());
  }

  public function testRemoteMatch_EmailIndeterminate_FirstLastSuccess() {
    [$remotePerson, $idOfMatchingContact, $idOf_Non_MatchingContact] =
      F::setUpRemotePerson_TwoLocalContactsMatchingByEmail_OneAlsoMatchingByName($this->system);
    $matchingContact = F::civiApi4GetSingleContactById($idOfMatchingContact);

    $matchResult1 = $this->matcher->tryToFindMatchForLocalObject(self::makePair($idOfMatchingContact));
    $this->assertNotNull($matchResult1->getMatch());
    $this->assertEquals($matchingContact['email.email'],
      $matchResult1->getMatch()->emailAddress->get());
    $this->assertEquals($matchingContact['first_name'],
      $matchResult1->getMatch()->givenName->get());
    $this->assertEquals($matchingContact['last_name'],
      $matchResult1->getMatch()->familyName->get());

    $matchResult2 = $this->matcher->tryToFindMatchForLocalObject(self::makePair($idOf_Non_MatchingContact));
    self::assertTrue($matchResult2->isError());
    self::assertEquals($matchResult2::ERROR_INDETERMINATE, $matchResult2->getStatusCode());
    self::assertNull($matchResult2->getMatch());
  }

  public function testRemoteMatch_EmailIndeterminate_NoMatchingFirstLast() {
    [$remotePerson, $idsOfContactsWithSameEmailAndDifferentName] =
      F::setUpRemotePerson_TwoLocalContactsMatchingByEmail_NeitherMatchingByName($this->system);

    foreach ($idsOfContactsWithSameEmailAndDifferentName as $id) {
      $matchResult = $this->matcher->tryToFindMatchForLocalObject(self::makePair($id));
      self::assertTrue($matchResult->isError());
      self::assertEquals($matchResult::ERROR_INDETERMINATE, $matchResult->getStatusCode());
      $this->assertNull($matchResult->getMatch());
    }
  }

  public function testLocalMatch_OneToOneEmailSuccess() {
    [$emailAddress, $remotePerson, $contactId] =
      F::setUpExactlyOneMatchByEmail_DifferentNames($this->system);
    $matchResult = $this->matcher->tryToFindMatchForRemoteObject(self::makePair($remotePerson));
    $this->assertNotNull($matchResult->getMatch());
    $this->assertEquals($emailAddress, $matchResult->getLocalObject()->loadOnce()->emailEmail->get());
  }

  public function testLocalMatch_OrganizationWithEmailIsIgnored() {
    [$emailAddress, $remotePerson, $contactId] =
      F::setUpExactlyOneMatchByEmail_DifferentNames($this->system);

    \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Organization')
      ->addValue('organization_name', 'foo')
      ->addChain('email', \Civi\Api4\Email::create(FALSE)
        ->addValue('contact_id', '$id')
        ->addValue('email', $emailAddress))
      ->execute();
    $emailCount = \Civi\Api4\Email::get(FALSE)
      ->addWhere('email', '=', $emailAddress)
      ->execute()->count();

    self::assertGreaterThan(1, $emailCount);

    $matchResult = $this->matcher->tryToFindMatchForRemoteObject(self::makePair($remotePerson));
    $this->assertTrue($matchResult->gotMatch());
    $this->assertEquals($emailAddress, $matchResult->getLocalObject()->loadOnce()->emailEmail->get());
  }

  public function testLocalMatch_NoMatchingEmail() {
    [$contactId, $remotePerson] =
      F::setUpLocalAndRemotePeople_SameName_DifferentEmail($this->system);
    $matchResult = $this->matcher->tryToFindMatchForRemoteObject(self::makePair($remotePerson));
    $this->assertMatchResultIsNotError_NoMatch_ZeroCount($matchResult);
  }

  public function testLocalMatch_EmailIndeterminate_FirstLastSuccess() {
    [$remotePerson, $idOfMatchingContact, $idOf_Non_MatchingContact] =
      F::setUpRemotePerson_TwoLocalContactsMatchingByEmail_OneAlsoMatchingByName($this->system);

    $matchResult = $this->matcher->tryToFindMatchForRemoteObject(self::makePair($remotePerson));
    $this->assertNotNull($matchResult->getMatch());
    $this->assertEquals($idOfMatchingContact,
      $matchResult->getMatch()->getId());

    $matchResult->getMatch()->loadOnce();

    $this->assertEquals($remotePerson->emailAddress->get(),
      $matchResult->getMatch()->emailEmail->get());
    $this->assertEquals($remotePerson->givenName->get(),
      $matchResult->getMatch()->firstName->get());
    $this->assertEquals($remotePerson->familyName->get(),
      $matchResult->getMatch()->lastName->get());
  }

  public function testLocalMatch_EmailIndeterminate_NoMatchingFirstLast() {
    [$remotePerson, $idsOfContactsWithSameEmailAndDifferentName] =
      F::setUpRemotePerson_TwoLocalContactsMatchingByEmail_NeitherMatchingByName($this->system);

    $matchResult = $this->matcher->tryToFindMatchForRemoteObject(self::makePair($remotePerson));
    self::assertTrue($matchResult->isError());
    $this->assertNull($matchResult->getMatch());
  }

  public function testLocalMatch_EmailIndeterminate_FirstLastIndeterminate() {
    [$remotePerson, $idsOfContactsWithSameEmailAndDifferentName] =
      F::setUpRemotePerson_TwoLocalContactsMatchingByEmail_BothMatchingByName($this->system);

    $matchResult = $this->matcher->tryToFindMatchForRemoteObject(self::makePair($remotePerson));
    self::assertTrue($matchResult->isError());
    self::assertNull($matchResult->getMatch());
  }

}
