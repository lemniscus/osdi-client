<?php

namespace Civi\Osdi;

use Civi\Api4\OsdiSyncProfile;
use Civi\OsdiClient;
use OsdiClient\ActionNetwork\TestUtils;
use PHPUnit;

/**
 * @group headless
 */
class ContainerTest extends PHPUnit\Framework\TestCase implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\TransactionalInterface {

  private static ActionNetwork\RemoteSystem $system;

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  protected function setUp(): void {
    self::$system = TestUtils::createRemoteSystem();
    parent::setUp();
  }

  public function testCreateContainerFromSyncProfileId() {
    $profile = OsdiSyncProfile::create(FALSE)
      ->addValue('entry_point', 'https://osdi.system')
      ->addValue('api_token', 'magic word')
      ->addValue('classes', [
        'LocalObject' => [
          'Tag' => \CRM_Core_BAO_Tag::class,
        ],
      ])
      ->execute()->single();

    $container = OsdiClient::container($profile['id']);
    $system = $container->getSingle('RemoteSystem', 'ActionNetwork');
    $tag = $container->make('LocalObject', 'Tag');

    self::assertEquals('https://osdi.system', $system->getEntryPoint());
    self::assertEquals(\CRM_Core_BAO_Tag::class, get_class($tag));
  }

  public function testCreateContainerFromDefaultSyncProfile() {
    OsdiSyncProfile::create(FALSE)
      ->addValue('is_default', TRUE)
      ->addValue('entry_point', 'https://osdi.system')
      ->addValue('api_token', 'magic word')
      ->addValue('classes', [
        'LocalObject' => [
          'Tag' => \CRM_Core_BAO_Tag::class,
        ],
      ])
      ->execute()->single();

    $container = OsdiClient::containerWithDefaultSyncProfile(TRUE);
    $system = $container->getSingle('RemoteSystem', 'ActionNetwork');
    $tag = $container->make('LocalObject', 'Tag');

    self::assertEquals('https://osdi.system', $system->getEntryPoint());
    self::assertEquals(\CRM_Core_BAO_Tag::class, get_class($tag));
  }

  public function testMakeDefault() {
    $remotePerson = OsdiClient::container()->make('OsdiObject', 'osdi:people', self::$system);
    self::assertEquals(ActionNetwork\Object\Person::class, get_class($remotePerson));

    $localPerson = OsdiClient::container()->make('LocalObject', 'Person', 99);
    self::assertEquals(LocalObject\PersonBasic::class, get_class($localPerson));
    self::assertEquals(99, $localPerson->getId());
  }

  public function testRegister() {
    $obj = OsdiClient::container()->make('SingleSyncer', 'Tag', self::$system);
    self::assertEquals(ActionNetwork\SingleSyncer\TagBasic::class, get_class($obj));

    \Civi\OsdiClient::container()->register('SingleSyncer', 'Tag', TestUtils::class);
    $obj = OsdiClient::container()->make('SingleSyncer', 'Tag');
    self::assertEquals(TestUtils::class, get_class($obj));
  }

  public function testCanMake() {
    self::assertFalse(\Civi\OsdiClient::container()->canMake('gobStopper', 'Everlasting'));
    \Civi\OsdiClient::container()->register('gobStopper', 'Everlasting', __CLASS__);
    self::assertTrue(\Civi\OsdiClient::container()->canMake('gobStopper', 'Everlasting'));
  }

  public function testSingleton() {
    $system = self::$system;

    /** @var \Civi\Osdi\ActionNetwork\SingleSyncer\Person\PersonBasic $s1 */
    $s1 = OsdiClient::container()->make('SingleSyncer', 'Person', $system);
    $s2 = OsdiClient::container()->make('SingleSyncer', 'Person', $system);

    $s1->setSyncProfile(['foo']);
    $s2->setSyncProfile(['bar']);
    self::assertNotEquals($s1, $s2);

    $s3 = OsdiClient::container()->getSingle('SingleSyncer', 'Person', $system);
    $s4 = OsdiClient::container()->getSingle('SingleSyncer', 'Person', $system);
    $s4->setSyncProfile(['baz']);

    self::assertIsObject($s3);
    self::assertNotEquals($s1, $s3);
    self::assertEquals($s3, $s4);
  }

}
