<?php

namespace Civi\Osdi\ActionNetwork;

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use OsdiClient\ActionNetwork\TestUtils;
use OsdiClient\FixtureHttpClient;

/**
 * @group headless
 */
class RemoteFindResultTest extends \PHPUnit\Framework\TestCase implements
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
    self::$system = TestUtils::createRemoteSystem();
  }

  public function setUp(): void {
    FixtureHttpClient::resetHistory();
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
      [['email_address', 'eq', 'traveler@antique.land']]);

    self::assertEquals(0, $remotePeopleWithTheEmail->filteredCurrentCount());

    $person->emailStatus->set('subscribed');
    $person->save();

    $remotePeopleWithTheEmail = self::$system->find(
      'osdi:people',
      [['email_address', 'eq', 'traveler@antique.land']]);

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

    // AN's person filter sometimes takes awhile to catch up with changes
    for ($start = time(); time() - $start < 4; $hadToRepeat = TRUE) {
      if (isset($hadToRepeat)) {
        print "\n" . __FILE__ . ':' . __LINE__ . ": AN search results haven't caught up to changes; trying again\n";
        usleep(500);
      }

      $remotePeopleWithTheName = self::$system->find(
        'osdi:people',
        [['given_name', 'eq', 'Thing']]);

      foreach ($remotePeopleWithTheName as $person) {
        if (
          'One' === $person->familyName->get() &&
          'subscribed' === $person->emailStatus->get()
        ) {
          break 2;
        }
      }
    }

    $first = $remotePeopleWithTheName->filteredFirst();

    self::assertEquals('One', $first->familyName->get());

    // TEST PROPER, PART B

    $thingOne->emailStatus->set('unsubscribed');
    $thingOne->save();

    $thingTwo->emailStatus->set('subscribed');
    $thingTwo->save();

    // AN's person filter sometimes takes awhile to catch up with changes
    for ($start = time(); time() - $start < 4; $hadToRepeat = TRUE) {
      if (isset($hadToRepeat)) {
        print "\n" . __FILE__ . ':' . __LINE__ . ": AN search results haven't caught up to changes; trying again\n";
        usleep(500);
      }

      $remotePeopleWithTheName = self::$system->find(
        'osdi:people',
        [['given_name', 'eq', 'Thing']]);

      foreach ($remotePeopleWithTheName as $person) {
        if (
          ('One' === $person->familyName->get() &&
           'unsubscribed' !== $person->emailStatus->get())
          ||
          ('Two' === $person->familyName->get() &&
           'subscribed' !== $person->emailStatus->get())
        ) {
          break 1;
        }
        break 2;
      }
    }

    $first = $remotePeopleWithTheName->filteredFirst();

    self::assertEquals('Two', $first->familyName->get());

  }

}
