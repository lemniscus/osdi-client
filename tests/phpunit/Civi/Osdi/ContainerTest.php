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

  public function testMakeWithDefaultRegistry() {
    $remotePerson = OsdiClient::container()->make('OsdiObject', 'osdi:people', self::$system);
    self::assertEquals(ActionNetwork\Object\Person::class, get_class($remotePerson));

    $localPerson = OsdiClient::container()->make('LocalObject', 'Person', 99);
    self::assertEquals(LocalObject\PersonBasic::class, get_class($localPerson));
    self::assertEquals(99, $localPerson->getId());
  }

  public function testSyncProfileModifiesDefaultRegistry() {
    // set up container with default registry, and make some things with it
    $defaultRegistryContainer = new Container();
    $defaultTag = $defaultRegistryContainer->make('LocalObject', 'Tag');
    $defaultPerson = $defaultRegistryContainer->make('LocalObject', 'Person');

    $replacementClass = \CRM_Utils_Array::class;

    // assertions regarding the default registry
    self::assertNotEquals($replacementClass, get_class($defaultTag));
    self::assertTrue($defaultRegistryContainer->canMake(
      'CrmEventResponder', 'Contact'));

    // set up a container with a modified registry; make some things with it
    $syncProfileId = OsdiSyncProfile::create(FALSE)
      ->addValue('is_default', TRUE)
      ->addValue('entry_point', 'https://osdi.system')
      ->addValue('api_token', 'magic word')
      ->addValue('classes', [
        'LocalObject' => [
          'Tag' => $replacementClass,
        ],
        'CrmEventResponder' => ['*' => NULL],
      ])
      ->execute()->single()['id'];

    $container = OsdiClient::container($syncProfileId);
    $tag = $container->make('LocalObject', 'Tag');
    $person = $container->make('LocalObject', 'Person');

    // assert that the modifications are in effect where explicitly changed,
    // and defaults are in effect otherwise
    self::assertEquals($replacementClass, get_class($tag));
    self::assertEquals(get_class($defaultPerson), get_class($person));
    self::assertFalse($container->canMake('CrmEventResponder', 'Contact'));

    // set up a container with all defaults erased
    OsdiSyncProfile::update(FALSE)
      ->addWhere('id', '=', $syncProfileId)
      ->addValue('classes', [
        '*' => ['*' => NULL],
        'LocalObject' => [
          'Tag' => $replacementClass,
        ],
      ])
      ->execute()->single()['id'];

    $container = OsdiClient::container($syncProfileId);
    $tag = $container->make('LocalObject', 'Tag');

    // assert that defaults have been cleared and modified registry is in effect
    self::assertEquals($replacementClass, get_class($tag));
    self::assertFalse($container->canMake('LocalObject', 'Person'));
  }

  public function testRegister() {
    $container = \Civi\OsdiClient::container();

    $obj = $container->make('SingleSyncer', 'Tag', self::$system);
    self::assertEquals(ActionNetwork\SingleSyncer\TagBasic::class, get_class($obj));

    $container->register('SingleSyncer', 'Tag', TestUtils::class);
    $obj = OsdiClient::container()->make('SingleSyncer', 'Tag');
    self::assertEquals(TestUtils::class, get_class($obj));

    $container->register('SingleSyncer', '*', NULL);
    self::assertFalse($container->canMake('SingleSyncer', 'Tag'));
  }

  public function testCanMake() {
    self::assertFalse(\Civi\OsdiClient::container()->canMake('gobStopper', 'Everlasting'));
    \Civi\OsdiClient::container()->register('gobStopper', 'Everlasting', __CLASS__);
    self::assertTrue(\Civi\OsdiClient::container()->canMake('gobStopper', 'Everlasting'));
  }

  public function testSingleton() {
    $system = self::$system;

    /** @var \Civi\Osdi\ActionNetwork\SingleSyncer\PersonBasic $s1 */
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
