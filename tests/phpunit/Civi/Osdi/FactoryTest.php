<?php

namespace Civi\Osdi;

use CRM_OSDI_ActionNetwork_TestUtils;
use PHPUnit;

/**
 * @group headless
 */
class FactoryTest extends PHPUnit\Framework\TestCase implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\TransactionalInterface {

  private static ActionNetwork\RemoteSystem $system;

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  protected function setUp(): void {
    self::$system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
    parent::setUp();
  }

  public function testCreateDefault() {
    $remotePerson = Factory::make('OsdiObject', 'osdi:people', self::$system);
    self::assertEquals(ActionNetwork\Object\Person::class, get_class($remotePerson));

    $localPerson = Factory::make('LocalObject', 'Person', 99);
    self::assertEquals(LocalObject\PersonBasic::class, get_class($localPerson));
    self::assertEquals(99, $localPerson->getId());
  }

  public function testRegister() {
    $obj = Factory::make('SingleSyncer', 'Tag', self::$system);
    self::assertEquals(ActionNetwork\SingleSyncer\TagBasic::class, get_class($obj));

    Factory::register('SingleSyncer', 'Tag', CRM_OSDI_ActionNetwork_TestUtils::class);
    $obj = Factory::make('SingleSyncer', 'Tag');
    self::assertEquals(CRM_OSDI_ActionNetwork_TestUtils::class, get_class($obj));
  }

}
