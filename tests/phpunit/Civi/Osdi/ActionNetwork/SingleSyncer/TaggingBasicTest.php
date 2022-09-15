<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer;

use Civi;
use Civi\Osdi\Result\MapAndWrite;
use CRM_OSDI_ActionNetwork_TestUtils;
use CRM_OSDI_Fixture_PersonMatching as PersonMatchFixture;
use PHPUnit;

/**
 * @group headless
 */
class TaggingBasicTest extends PHPUnit\Framework\TestCase implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\TransactionalInterface {

  private static \Civi\Osdi\ActionNetwork\SingleSyncer\TaggingBasic $syncer;

  private static \Civi\Osdi\ActionNetwork\RemoteSystem $remoteSystem;

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public static function setUpBeforeClass(): void {
    PersonMatchFixture::$personClass = \Civi\Osdi\ActionNetwork\Object\Person::class;

    self::$remoteSystem = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
  }

  protected function setUp(): void {
    $syncer = self::makeNewSyncer();
    self::$syncer = $syncer;
    parent::setUp();
  }

  /**
   * @return array{0: \Civi\Osdi\LocalObject\TaggingBasic, 1: \Civi\Osdi\ActionNetwork\Object\Tagging}
   */
  private function makeSameTaggingOnBothSides(): array {
    $remotePerson = new \Civi\Osdi\ActionNetwork\Object\Person(self::$remoteSystem);
    $remotePerson->emailAddress->set('taggingsyncertest@test.net');
    $remotePerson->givenName->set('Test Tagging Sync');
    $remotePerson->save();

    $localPerson = new \Civi\Osdi\LocalObject\PersonBasic();
    $localPerson->emailEmail->set('taggingsyncertest@test.net');
    $localPerson->firstName->set('Test Tagging Sync');
    $localPerson->save();

    $remoteTag = new \Civi\Osdi\ActionNetwork\Object\Tag(self::$remoteSystem);
    $remoteTag->name->set('test tagging sync');
    $remoteTag->save();

    $localTag = new \Civi\Osdi\LocalObject\TagBasic();
    $localTag->name->set('test tagging sync');
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

  private static function makeNewSyncer(): \Civi\Osdi\ActionNetwork\SingleSyncer\TaggingBasic {
    $remoteSystem = self::$remoteSystem;

    $personSyncer = new \Civi\Osdi\ActionNetwork\SingleSyncer\Person\PersonBasic($remoteSystem);
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

  public function testOneWayMapAndWrite_NoTwinGiven_FromLocal() {
    $taggingSyncer = self::$syncer;

    $localPerson = new Civi\Osdi\LocalObject\PersonBasic();
    $localPerson->emailEmail->set('taggingtest' . uniqid() . '@test.net');
    $localPerson->firstName->set('Test Tagging Sync');
    $localPerson->save();

    $localTag = new \Civi\Osdi\LocalObject\TagBasic();
    $localTag->name->set('test tagging sync');
    $localTag->save();

    $localTagging = new \Civi\Osdi\LocalObject\TaggingBasic();
    $localTagging->setPerson($localPerson);
    $localTagging->setTag($localTag);

    $pair = $taggingSyncer->toLocalRemotePair($localTagging);
    $pair->setOrigin($pair::ORIGIN_LOCAL);

    self::assertNull($pair->getRemoteObject());

    // FIRST SYNC -- NO MATCH

    $taggingSyncer->oneWayMapAndWrite($pair);
    $resultStack = $pair->getResultStack();
    $lastResult = $resultStack->last();

    self::assertEquals(MapAndWrite::class, get_class($lastResult));
    self::assertTrue($resultStack->lastIsError());

    self::assertEquals(MapAndWrite::ERROR,
        $lastResult->getStatusCode());

    // SECOND SYNC -- WITH MATCH

    $localPerson->emailEmail->set('taggingsyncertest@test.net');

    $taggingSyncer->getPersonSyncer()->syncFromLocalIfNeeded($localPerson);
    $taggingSyncer->getTagSyncer()->matchAndSyncIfEligible($localTag);

    $taggingSyncer->oneWayMapAndWrite($pair);
    $resultStack = $pair->getResultStack();
    $lastResult = $resultStack->last();

    self::assertEquals(MapAndWrite::class, get_class($lastResult));
    self::assertFalse($resultStack->lastIsError());

    self::assertEquals(MapAndWrite::WROTE_NEW,
        $lastResult->getStatusCode());

    self::assertNotNull($pair->getRemoteObject()->getTag()->getId());

    self::assertEquals(
        $pair->getLocalObject()->getTag()->name->get(),
        $pair->getRemoteObject()->getTag()->name->get());
  }

  public function testOneWayMapAndWrite_NoTwinGiven_FromRemote() {
    $taggingSyncer = self::$syncer;

    $remotePerson = new Civi\Osdi\ActionNetwork\Object\Person(self::$remoteSystem);
    $remotePerson->emailAddress->set('taggingsyncertest@test.net');
    $remotePerson->givenName->set('Test Tagging Sync');
    $remotePerson->save();

    $remoteTag = new \Civi\Osdi\ActionNetwork\Object\Tag(self::$remoteSystem);
    $remoteTag->name->set('test tagging sync');
    $remoteTag->save();

    $remoteTagging = new \Civi\Osdi\ActionNetwork\Object\Tagging(self::$remoteSystem);
    $remoteTagging->setPerson($remotePerson);
    $remoteTagging->setTag($remoteTag);

    $pair = $taggingSyncer->toLocalRemotePair(NULL, $remoteTagging);
    $pair->setOrigin($pair::ORIGIN_REMOTE);

    self::assertNull($pair->getLocalObject());

    // FIRST SYNC -- NO MATCH

    $taggingSyncer->oneWayMapAndWrite($pair);
    $resultStack = $pair->getResultStack();
    $lastResult = $resultStack->last();

    self::assertEquals(MapAndWrite::class, get_class($lastResult));
    self::assertTrue($resultStack->lastIsError());

    self::assertEquals(MapAndWrite::ERROR,
      $lastResult->getStatusCode());

    // SECOND SYNC -- WITH MATCH

    $taggingSyncer->getPersonSyncer()->syncFromRemoteIfNeeded($remotePerson);
    $taggingSyncer->getTagSyncer()->matchAndSyncIfEligible($remoteTag);

    try {
      $taggingSyncer->oneWayMapAndWrite($pair);
    }
    catch (\Throwable $e) {
      self::fail('A DB constraint error pops up ONLY if this test function is run 
      after certain others, which is strange because we\'re using TransactionalInterface. 
      See Civi log file (CONSTRAINT `FK_civicrm_entity_tag_tag_id` FOREIGN KEY (`tag_id`) 
      REFERENCES `civicrm_tag` (`id`))');
    }

    $resultStack = $pair->getResultStack();
    $lastResult = $resultStack->last();

    self::assertEquals(MapAndWrite::class, get_class($lastResult));
    self::assertFalse($resultStack->lastIsError());

    self::assertEquals(MapAndWrite::WROTE_NEW,
      $lastResult->getStatusCode());

    self::assertNotNull($pair->getLocalObject()->getTag()->getId());

    self::assertEquals(
      $pair->getRemoteObject()->getTag()->name->get(),
      $pair->getLocalObject()->getTag()->name->get());
  }

  public function testOneWayMapAndWrite_TwinGiven_ThrowsError() {
    $taggingSyncer = self::$syncer;
    [$localTagging, $remoteTagging] = $this->makeSameTaggingOnBothSides();
    $pair = $taggingSyncer->toLocalRemotePair($localTagging, $remoteTagging);

    $pair->setOrigin($pair::ORIGIN_REMOTE);
    try {
      $taggingSyncer->oneWayMapAndWrite($pair);
      self::fail('OneWayMayAndWrite() should throw error when twin is given');
    }
    catch (\Civi\Osdi\Exception\InvalidOperationException $e) {
    }

    $pair->setOrigin($pair::ORIGIN_LOCAL);
    try {
      $taggingSyncer->oneWayMapAndWrite($pair);
      self::fail('OneWayMayAndWrite() should throw error when twin is given');
    }
    catch (\Civi\Osdi\Exception\InvalidOperationException $e) {
    }

    // this is redundant, but we have to assert something
    self::assertNotEmpty($e);
  }

  public function testSyncDeletion_FromLocal() {
    $taggingSyncer = self::$syncer;
    [$localTagging, $remoteTagging] = $this->makeSameTaggingOnBothSides();

    $pair = $taggingSyncer->toLocalRemotePair($localTagging, NULL);
    $pair->setOrigin($pair::ORIGIN_LOCAL);

    self::assertNotNull($remoteTagging->getId());
    self::assertNull($pair->getRemoteObject());

    $taggingSyncer->syncDeletion($pair);
    $resultStack = $pair->getResultStack();
    $lastResult = $resultStack->last();

    self::assertEquals(Civi\Osdi\Result\DeletionSync::class, get_class($lastResult));
    self::assertFalse($resultStack->lastIsError());
    self::assertEquals(Civi\Osdi\Result\DeletionSync::DELETED, $lastResult->getStatusCode());

    self::expectException(Civi\Osdi\Exception\EmptyResultException::class);
    $remoteTagging->load();
  }

  public function testSyncDeletion_FromRemote() {
    $taggingSyncer = self::$syncer;
    [$localTagging, $remoteTagging] = $this->makeSameTaggingOnBothSides();

    $pair = $taggingSyncer->toLocalRemotePair(NULL, $remoteTagging);
    $pair->setOrigin($pair::ORIGIN_REMOTE);

    self::assertNotNull($localTagging->getId());
    self::assertNull($pair->getLocalObject());

    $taggingSyncer->syncDeletion($pair);
    $resultStack = $pair->getResultStack();
    $lastResult = $resultStack->last();

    self::assertEquals(Civi\Osdi\Result\DeletionSync::class, get_class($lastResult));
    self::assertFalse($resultStack->lastIsError());
    self::assertEquals(Civi\Osdi\Result\DeletionSync::DELETED, $lastResult->getStatusCode());

    try {
      $localTagging->load();
      self::fail('Tagging should not be loadable after deletion');
    }
    catch (Civi\Osdi\Exception\InvalidArgumentException $e) {
    }
  }

  public function testMatchAndSyncIfEligible_WriteNewTwin_FromLocal() {
    self::markTestIncomplete('not yet implemented - low priority');
  }

  public function testMatchAndSyncIfEligible_WriteNewTwin_FromRemote() {
    self::markTestIncomplete('not yet implemented - low priority');
  }

  public function testMatchAndSyncIfEligible_MatchExistingTwin_FromRemote() {
    self::markTestIncomplete('not yet implemented - low priority');
  }

}
