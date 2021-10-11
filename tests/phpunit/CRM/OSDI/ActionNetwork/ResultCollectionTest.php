<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class CRM_OSDI_ActionNetwork_ResultCollectionTest extends \PHPUnit\Framework\TestCase implements
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

  public function testUnsubscribedPeopleAreNotCounted() {
    $unsavedPerson = new \Civi\Osdi\ActionNetwork\OsdiPerson(
      NULL,
      [
        'given_name' => 'Ozzy',
        'family_name' => 'Mandias',
        'email_addresses' => [
          [
            'address' => 'traveler@antique.land',
            'status' => 'unsubscribed',
          ],
        ],
        'phone_numbers' => [
          [
            'number' => '12021234444',
            'status' => 'unsubscribed',
          ],
        ],
      ]
    );
    $savedPerson = self::$system->save($unsavedPerson);

    $remotePeopleWithTheEmail = self::$system->find(
      'osdi:people',
      [['email', 'eq', 'traveler@antique.land']]);

    self::assertEquals(0, $remotePeopleWithTheEmail->filteredCurrentCount());

    $savedPerson->set(
      'email_addresses',
      [
        [
          'address' => 'traveler@antique.land',
          'status' => 'subscribed',
        ],
      ]
    );
    self::$system->save($savedPerson);

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
      self::$system->delete($person);
    }

    $thingOneUnsaved = new \Civi\Osdi\ActionNetwork\OsdiPerson(
      NULL,
      [
        'given_name' => 'Thing',
        'family_name' => 'One',
        'email_addresses' => [
          [
            'address' => 'one@big.red.wood.box',
            'status' => 'subscribed',
          ],
        ],
        'phone_numbers' => [['status' => 'unsubscribed']],
      ]
    );
    $thingOneSaved = self::$system->save($thingOneUnsaved);

    $thingTwoUnsaved = new \Civi\Osdi\ActionNetwork\OsdiPerson(
      NULL,
      [
        'given_name' => 'Thing',
        'family_name' => 'Two',
        'email_addresses' => [
          [
            'address' => 'two@big.red.wood.box',
            'status' => 'unsubscribed',
          ],
        ],
        'phone_numbers' => [['status' => 'unsubscribed']],
      ]
    );
    $thingTwoSaved = self::$system->save($thingTwoUnsaved);

    // TEST PROPER, PART A

    $remotePeopleWithTheName = self::$system->find(
      'osdi:people',
      [['given_name', 'eq', 'Thing']]);

    $first = $remotePeopleWithTheName->filteredFirst();

    self::assertEquals('One', $first->get('family_name'));

    // TEST PROPER, PART B

    $thingOneSaved->set('email_addresses', [['status' => 'unsubscribed']]);
    self::$system->save($thingOneSaved);

    $thingTwoSaved->set('email_addresses', [['status' => 'subscribed']]);
    self::$system->save($thingTwoSaved);

    $remotePeopleWithTheName = self::$system->find(
      'osdi:people',
      [['given_name', 'eq', 'Thing']]);

    $first = $remotePeopleWithTheName->filteredFirst();

    self::assertEquals('Two', $first->get('family_name'));

  }

}
