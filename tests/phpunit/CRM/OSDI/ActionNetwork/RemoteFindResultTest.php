<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class CRM_OSDI_ActionNetwork_RemoteFindResultTest extends \PHPUnit\Framework\TestCase implements
    HeadlessInterface,
    TransactionalInterface {

  /**
   * @var \Civi\Osdi\ActionNetwork\RemoteSystem
   */
  private static $system;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public static function setUpBeforeClass(): void {
    self::$system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
  }

  public function setUp(): void {
    CRM_OSDI_FixtureHttpClient::resetHistory();
    parent::setUp();
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  public function testUnsubscribedPeopleAreNotIncludedInFilteredCount() {
    $person = new \Civi\Osdi\ActionNetwork\Object\Person(self::$system);
    $person->givenName->set('Ozzy');
    $person->familyName->set('Mandias');
    $person->emailAddress->set('traveler@antique.land');
    $person->emailStatus->set('unsubscribed');
    $person->phoneNumber->set('12021234444');
    $person->phoneStatus->set('unsubscribed');
    $person->save();

    $remotePeopleWithTheEmail = self::$system->find(
      'osdi:people',
      [['email', 'eq', 'traveler@antique.land']]);

    self::assertEquals(0, $remotePeopleWithTheEmail->filteredCurrentCount());

    $person->emailStatus->set('subscribed');
    $person->save();

    $remotePeopleWithTheEmail = self::$system->find(
      'osdi:people',
      [['email', 'eq', 'traveler@antique.land']]);

    self::assertEquals(1, $remotePeopleWithTheEmail->filteredCurrentCount());
  }

  public function testFirst() {
    // SETUP

    $remotePeopleWithTheName = self::$system->find(
      'osdi:people',
      [['given_name', 'eq', 'Thing']]);

    foreach ($remotePeopleWithTheName->toArray() as $person) {
      $person->delete();
    }

    $thingOne = new \Civi\Osdi\ActionNetwork\Object\Person(self::$system);
    $thingOne->givenName->set('Thing');
    $thingOne->familyName->set('One');
    $thingOne->emailAddress->set('one@big.red.wood.box');
    $thingOne->emailStatus->set('subscribed');
    $thingOne->phoneStatus->set('unsubscribed');
    $thingOne->save();

    $thingTwo = new \Civi\Osdi\ActionNetwork\Object\Person(self::$system);
    $thingTwo->givenName->set('Thing');
    $thingTwo->familyName->set('Two');
    $thingTwo->emailAddress->set('two@big.red.wood.box');
    $thingTwo->emailStatus->set('unsubscribed');
    $thingTwo->phoneStatus->set('unsubscribed');
    $thingTwo->save();

    // TEST PROPER, PART A

    $remotePeopleWithTheName = self::$system->find(
      'osdi:people',
      [['given_name', 'eq', 'Thing']]);

    $first = $remotePeopleWithTheName->filteredFirst();

    self::assertEquals('One', $first->familyName->get());

    // TEST PROPER, PART B

    $thingOne->emailStatus->set('unsubscribed');
    $thingOne->save();

    $thingTwo->emailStatus->set('subscribed');
    $thingTwo->save();

    $remotePeopleWithTheName = self::$system->find(
      'osdi:people',
      [['given_name', 'eq', 'Thing']]);

    $first = $remotePeopleWithTheName->filteredFirst();

    self::assertEquals('Two', $first->familyName->get());

  }

}
