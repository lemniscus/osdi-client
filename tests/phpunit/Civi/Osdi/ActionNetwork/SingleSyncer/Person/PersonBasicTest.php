<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer;

use Civi\Osdi\ActionNetwork\Object\Person as ANPerson;
use Civi\OsdiClient;
use OsdiClient\ActionNetwork\PersonMatchingFixture as PersonMatchFixture;
use OsdiClient\ActionNetwork\TestUtils;

/**
 * @group headless
 */
class PersonBasicTest extends PersonTestAbstract implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    self::$remoteSystem = TestUtils::createRemoteSystem();

    PersonMatchFixture::$personClass = ANPerson::class;
    PersonMatchFixture::$remoteSystem = self::$remoteSystem;

    $container = OsdiClient::container();
    $container->register('SingleSyncer', 'Person', PersonBasic::class);
    self::$syncer = $container->make('SingleSyncer', 'Person');

    parent::setUp();;
  }

}
