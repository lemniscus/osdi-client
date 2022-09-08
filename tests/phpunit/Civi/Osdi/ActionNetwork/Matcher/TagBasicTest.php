<?php

namespace Civi\Osdi\ActionNetwork\Matcher;

use Civi\Osdi\ActionNetwork\Object\Tag;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\RemoteObjectInterface;
use CRM_OSDI_ActionNetwork_TestUtils;
use CRM_OSDI_Fixture_PersonMatching as PersonMatchFixture;
use PHPUnit;

/**
 * @group headless
 */
class TagBasicTest extends PHPUnit\Framework\TestCase implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\TransactionalInterface {

  private static \Civi\Osdi\ActionNetwork\Matcher\TagBasic $matcher;

  private static \Civi\Osdi\ActionNetwork\RemoteSystem $remoteSystem;

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public static function setUpBeforeClass(): void {
    PersonMatchFixture::$personClass = \Civi\Osdi\ActionNetwork\Object\Person::class;

    self::$remoteSystem = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
    self::$matcher = self::makeNewMatcher();
  }

  private static function makeNewMatcher(): TagBasic {
    return new \Civi\Osdi\ActionNetwork\Matcher\TagBasic(self::$remoteSystem);
  }

  public function testRemoteMatch_Success() {
    $localTag = new \Civi\Osdi\LocalObject\TagBasic();
    $localTag->name->set('Test Tag match');
    $localTag->save();

    $remoteTag = new Tag(self::$remoteSystem);
    $remoteTag->name->set('Test Tag match');
    $remoteTag->save();

    $pair = (new LocalRemotePair())
      ->setLocalClass(get_class($localTag))
      ->setRemoteClass(get_class($remoteTag))
      ->setOrigin(LocalRemotePair::ORIGIN_LOCAL)
      ->setLocalObject($localTag);

    $matchFindResult = self::$matcher->tryToFindMatchFor($pair);

    self::assertFalse($matchFindResult->isError());
    self::assertTrue($matchFindResult->gotMatch());
    self::assertEquals($remoteTag->getId(), $matchFindResult->getMatch()->getId());
  }

}
