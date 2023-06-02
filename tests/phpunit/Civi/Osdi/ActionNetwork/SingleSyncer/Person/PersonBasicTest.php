<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer;

use Civi\Osdi\ActionNetwork\Object\Person as ANPerson;
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

    self::$syncer = new PersonBasic(self::$remoteSystem);
    self::$syncer->setSyncProfile(TestUtils::createSyncProfile());

    parent::setUp();;
  }

}
