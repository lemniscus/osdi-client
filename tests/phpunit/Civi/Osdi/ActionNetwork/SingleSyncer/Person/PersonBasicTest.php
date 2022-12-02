<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer\Person;

use Civi\Osdi\ActionNetwork\Object\Person as ANPerson;
use Civi\Osdi\ActionNetwork\SingleSyncer\PersonTestAbstract;
use CRM_OSDI_ActionNetwork_TestUtils;
use CRM_OSDI_Fixture_PersonMatching as PersonMatchFixture;

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
    self::$remoteSystem = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    PersonMatchFixture::$personClass = ANPerson::class;
    PersonMatchFixture::$remoteSystem = self::$remoteSystem;

    self::$syncer = new PersonBasic(self::$remoteSystem);
    self::$syncer->setSyncProfile(CRM_OSDI_ActionNetwork_TestUtils::createSyncProfile());

    parent::setUp();;
  }

}
