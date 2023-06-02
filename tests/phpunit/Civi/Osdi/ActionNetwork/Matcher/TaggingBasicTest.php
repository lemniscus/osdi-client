<?php

namespace Civi\Osdi\ActionNetwork\Matcher;

use Civi\Osdi\RemoteObjectInterface;
use OsdiClient\ActionNetwork\PersonMatchingFixture as PersonMatchFixture;
use OsdiClient\ActionNetwork\TestUtils;
use PHPUnit;

/**
 * @group headless
 */
class TaggingBasicTest extends PHPUnit\Framework\TestCase implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\TransactionalInterface {

  private static \Civi\Osdi\ActionNetwork\Matcher\TaggingBasic $matcher;

  private static \Civi\Osdi\ActionNetwork\RemoteSystem $remoteSystem;

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public static function setUpBeforeClass(): void {
    PersonMatchFixture::$personClass = \Civi\Osdi\ActionNetwork\Object\Person::class;

    self::$remoteSystem = TestUtils::createRemoteSystem();
    self::$matcher = self::makeNewMatcher();
  }

  protected function setUp(): void {
  }

  protected function tearDown(): void {
    parent::tearDown();
  }

  public static function tearDownAfterClass(): void {
  }

  private static function makeNewMatcher(): TaggingBasic {
    $syncer = self::makeNewSyncer();
    $matcher = $syncer->getMatcher();
    /** @var \Civi\Osdi\ActionNetwork\Matcher\TaggingBasic $matcher */
    return $matcher;
  }

  private static function makeNewSyncer(): \Civi\Osdi\ActionNetwork\SingleSyncer\TaggingBasic {
    $remoteSystem = self::$remoteSystem;

    $personSyncer = new \Civi\Osdi\ActionNetwork\SingleSyncer\PersonBasic($remoteSystem);
    $personSyncer->setMapper(new \Civi\Osdi\ActionNetwork\Mapper\PersonBasic($remoteSystem))
      ->setMatcher(new \Civi\Osdi\ActionNetwork\Matcher\Person\UniqueEmailOrFirstLastEmail($remoteSystem));

    $tagSyncer = new \Civi\Osdi\ActionNetwork\SingleSyncer\TagBasic($remoteSystem);
    $tagSyncer->setMapper(new \Civi\Osdi\ActionNetwork\Mapper\TagBasic())
      ->setMatcher(new \Civi\Osdi\ActionNetwork\Matcher\TagBasic($remoteSystem));

    $taggingSyncer = new \Civi\Osdi\ActionNetwork\SingleSyncer\TaggingBasic($remoteSystem);
    $taggingSyncer->setMapper(new \Civi\Osdi\ActionNetwork\Mapper\TaggingBasic($taggingSyncer))
      ->setMatcher(new \Civi\Osdi\ActionNetwork\Matcher\TaggingBasic($taggingSyncer))
      ->setPersonSyncer($personSyncer)
      ->setTagSyncer($tagSyncer);

    return $taggingSyncer;
  }

  private static function makePair($input): \Civi\Osdi\LocalRemotePair {
    $pair = new \Civi\Osdi\LocalRemotePair();
    $pair->setLocalClass(\Civi\Osdi\LocalObject\TaggingBasic::class);
    $pair->setRemoteClass(\Civi\Osdi\ActionNetwork\Object\Tagging::class);
    if (is_a($input, RemoteObjectInterface::class)) {
      $pair->setRemoteObject($input)->setOrigin($pair::ORIGIN_REMOTE);
    }
    else {
      $pair->setLocalObject($input)->setOrigin($pair::ORIGIN_LOCAL);
    }
    return $pair;
  }

  /**
   * @return array{0: \Civi\Osdi\LocalObject\TaggingBasic, 1: \Civi\Osdi\ActionNetwork\Object\Tagging}
   */
  private function makeSameTaggingOnBothSides(): array {
    $remotePerson = new \Civi\Osdi\ActionNetwork\Object\Person(self::$remoteSystem);
    $remotePerson->emailAddress->set('taggingmatchertest@test.net');
    $remotePerson->givenName->set('Test Tagging Matcher');
    $remotePerson->save();

    $localPerson = new \Civi\Osdi\LocalObject\PersonBasic();
    $localPerson->emailEmail->set('taggingmatchertest@test.net');
    $localPerson->firstName->set('Test Tagging Matcher');
    $localPerson->save();

    $remoteTag = new \Civi\Osdi\ActionNetwork\Object\Tag(self::$remoteSystem);
    $remoteTag->name->set('Test Tagging Matcher');
    $remoteTag->save();

    $localTag = new \Civi\Osdi\LocalObject\TagBasic();
    $localTag->name->set('Test Tagging Matcher');
    $localTag->save();

    $remoteTagging = new \Civi\Osdi\ActionNetwork\Object\Tagging(self::$remoteSystem);
    $remoteTagging->setPerson($remotePerson);
    $remoteTagging->setTag($remoteTag);
    $remoteTagging->save();

    $localTagging = new \Civi\Osdi\LocalObject\TaggingBasic();
    $localTagging->setPerson($localPerson)->setTag($localTag);
    $localTagging->save();

    return [$localTagging, $remoteTagging];
  }

  public function testRemoteMatch_Success_NoSavedMatches() {
    [$localTagging, $remoteTagging] = $this->makeSameTaggingOnBothSides();

    self::assertNotNull($remoteTagging->getId());

    $pair = $this->makePair($localTagging);
    $matchFindResult = self::$matcher->tryToFindMatchFor($pair);

    self::assertFalse($matchFindResult->isError());
    self::assertTrue($matchFindResult->gotMatch());
    self::assertEquals($remoteTagging->getId(), $matchFindResult->getMatch()->getId());
  }

  public function testLocalMatch_Success_NoSavedMatches() {
    [$localTagging, $remoteTagging] = $this->makeSameTaggingOnBothSides();

    self::assertNotNull($localTagging->getId());

    $pair = $this->makePair($remoteTagging);
    $matchFindResult = self::$matcher->tryToFindMatchFor($pair);

    self::assertFalse($matchFindResult->isError());
    self::assertTrue($matchFindResult->gotMatch());
    self::assertEquals($localTagging->getId(), $matchFindResult->getMatch()->getId());
  }

  public function testLocalMatch_Success_WithSavedMatches() {
    // could also be made part of preceding tests
    self::markTestIncomplete('Todo');
  }

}
