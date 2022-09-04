<?php

use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Result\FetchOldOrFindNewMatch;
use Civi\Osdi\Result\MapAndWrite;
use Civi\Osdi\Result\Sync;
use Civi\Osdi\Result\SyncEligibility;
use CRM_OSDI_Fixture_PersonMatching as PersonMatchFixture;

/**
 * @group headless
 */
class CRM_OSDI_ActionNetwork_SingleSyncer_TaggingBasicTest extends PHPUnit\Framework\TestCase implements
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
    $syncer = self::makeNewSyncer();
    self::$syncer = $syncer;
  }

  protected function setUp(): void {
  }

  protected function tearDown(): void {
    parent::tearDown();
  }

  public static function tearDownAfterClass(): void {
  }

  private function makeSameTaggingOnBothSides(): array {
    $remotePerson = new Civi\Osdi\ActionNetwork\Object\Person(self::$remoteSystem);
    $remotePerson->emailAddress->set('taggingtest@test.net');
    $remotePerson->givenName->set('Test Tagging Sync');
    $remotePerson->save();
    
    $localPerson = new \Civi\Osdi\LocalObject\PersonBasic();
    $localPerson->emailEmail->set('taggingtest@test.net');
    $localPerson->firstName->set('Test Tagging Sync');
    $localPerson->save();
    
    $remoteTag = new \Civi\Osdi\ActionNetwork\Object\Tag(self::$remoteSystem);
    $remoteTag->name->set('test tagging sync');
    $remoteTag->save();

    $localTag  = new \Civi\Osdi\LocalObject\TagBasic();
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

    $personSyncer = new Civi\Osdi\ActionNetwork\SingleSyncer\Person\PersonBasic($remoteSystem);
    $personSyncer->setMapper(new \Civi\Osdi\ActionNetwork\Mapper\PersonBasic($remoteSystem))
      ->setMatcher(new \Civi\Osdi\ActionNetwork\Matcher\Person\UniqueEmailOrFirstLastEmail($remoteSystem));

    $tagSyncer = new \Civi\Osdi\ActionNetwork\SingleSyncer\TagBasic($remoteSystem);
    $tagSyncer->setMapper(new \Civi\Osdi\ActionNetwork\Mapper\TagBasic())
      ->setMatcher(new \Civi\Osdi\ActionNetwork\Matcher\TagBasic());

    $taggingSyncer = new \Civi\Osdi\ActionNetwork\SingleSyncer\TaggingBasic($remoteSystem);
    $taggingSyncer->setMapper(new \Civi\Osdi\ActionNetwork\Mapper\TaggingBasic($taggingSyncer))
      ->setMatcher(new \Civi\Osdi\ActionNetwork\Matcher\TaggingBasic($taggingSyncer))
      ->setPersonSyncer($personSyncer)
      ->setTagSyncer($tagSyncer);

    return $taggingSyncer;
  }

  public function testOneWayMapAndWrite_NoTwinGiven_FromRemote() {
    $taggingSyncer = self::$syncer;

    $remotePerson = new Civi\Osdi\ActionNetwork\Object\Person(self::$remoteSystem);
    $remotePerson->emailAddress->set('taggingtest@test.net');
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
    catch (Throwable $e) {
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

  public function testOneWayMapAndWrite_NoTwinGiven_FromLocal() {
    $taggingSyncer = self::$syncer;

    $localPerson = new Civi\Osdi\LocalObject\PersonBasic();
    $localPerson->emailEmail->set('taggingtest' . uniqid() .  '@test.net');
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

    $localPerson->emailEmail->set('taggingtest@test.net');

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

  public function testOneWayMapAndWrite_TwinGiven_ThrowsError() {
    $taggingSyncer = self::$syncer;
    [$localTagging, $remoteTagging] = $this->makeSameTaggingOnBothSides();
    $pair = $taggingSyncer->toLocalRemotePair($localTagging, $remoteTagging);

    $pair->setOrigin($pair::ORIGIN_REMOTE);
    self::expectException(\Civi\Osdi\Exception\InvalidOperationException::class);
    $taggingSyncer->oneWayMapAndWrite($pair);

    $pair->setOrigin($pair::ORIGIN_LOCAL);
    self::expectException(\Civi\Osdi\Exception\InvalidOperationException::class);
    $taggingSyncer->oneWayMapAndWrite($pair);
  }

  public function testMatchAndSyncIfEligible_WriteNewTwin_FromLocal() {
    self::markTestIncomplete();
    $taggingSyncer = self::$syncer;

    $localPerson = new Civi\Osdi\LocalObject\PersonBasic();
    $localPerson->emailEmail->set('taggingtest' . uniqid() .  '@test.net');
    $localPerson->firstName->set('Test Tagging Sync');
    $localPerson->save();

    $localTag = new \Civi\Osdi\LocalObject\TagBasic();
    $localTag->name->set('test tagging sync');
    $localTag->save();

    $localTagging = new \Civi\Osdi\LocalObject\TaggingBasic();
    $localTagging->setPerson($localPerson);
    $localTagging->setTag($localTag);

//    $pair = $taggingSyncer->toLocalRemotePair($localTag);
//    $pair->setOrigin($pair::ORIGIN_LOCAL);

//    self::assertNull($pair->getRemoteObject());

    // FIRST SYNC
    $pair = $taggingSyncer->matchAndSyncIfEligible($localTagging);
    $resultStack = $pair->getResultStack();
    $fetchFindMatchResult = $resultStack->getLastOfType(FetchOldOrFindNewMatch::class);
    $matchResult = $resultStack->getLastOfType(\Civi\Osdi\Result\MatchResult::class);
    $mapAndWriteResult = $resultStack->getLastOfType(MapAndWrite::class);
    $syncEligibleResult = $resultStack->getLastOfType(SyncEligibility::class);
    $syncResult = $resultStack->getLastOfType(Sync::class);
    $savedMatch = $syncResult->getState();

    self::assertEquals(FetchOldOrFindNewMatch::ERROR, $fetchFindMatchResult->getStatusCode());

    self::assertEquals(
      'Tag and Person must exist on both systems before syncing Tagging',
      $matchResult->getMessage());

    self::assertNull($mapAndWriteResult);
    self::assertNull($syncEligibleResult);
    self::assertEquals(Sync::ERROR, $syncResult->getStatusCode());
    self::assertNull($savedMatch);

    self::markTestIncomplete();



    // ================================





    self::assertEquals('testMatchAndSyncIfEligible_FromLocal',
      $pair->getRemoteObject()->name->get());

    // SECOND SYNC
    $pair = $syncer->matchAndSyncIfEligible($localTag);
    $resultStack = $pair->getResultStack();
    $fetchFindMatchResult = $resultStack->getLastOfType(FetchOldOrFindNewMatch::class);
    $mapAndWriteResult = $resultStack->getLastOfType(MapAndWrite::class);
    $syncEligibleResult = $resultStack->getLastOfType(SyncEligibility::class);
    $syncResult = $resultStack->getLastOfType(Sync::class);
    $savedMatch = $syncResult->getState();

    self::assertEquals(FetchOldOrFindNewMatch::FETCHED_SAVED_MATCH, $fetchFindMatchResult->getStatusCode());
    self::assertEquals(MapAndWrite::NO_CHANGES_TO_WRITE, $mapAndWriteResult->getStatusCode());
    self::assertEquals(SyncEligibility::ELIGIBLE, $syncEligibleResult->getStatusCode());
    self::assertEquals(Sync::SUCCESS, $syncResult->getStatusCode());
    self::assertEquals(LocalRemotePair::class, get_class($savedMatch));
    self::assertNotNull($pair->getRemoteObject()->getId());

    self::assertEquals('testMatchAndSyncIfEligible_FromLocal',
      $pair->getRemoteObject()->name->get());

    // THIRD SYNC
    $localTag->name->set('testMatchAndSyncIfEligible_FromLocal (new name)');
    $pair = $syncer->matchAndSyncIfEligible($localTag);
    $resultStack = $pair->getResultStack();
    $fetchFindMatchResult = $resultStack->getLastOfType(FetchOldOrFindNewMatch::class);
    $mapAndWriteResult = $resultStack->getLastOfType(MapAndWrite::class);
    $syncEligibleResult = $resultStack->getLastOfType(SyncEligibility::class);
    $syncResult = $resultStack->getLastOfType(Sync::class);
    $savedMatch = $syncResult->getState();

    self::assertEquals(FetchOldOrFindNewMatch::FETCHED_SAVED_MATCH, $fetchFindMatchResult->getStatusCode());
    self::assertEquals(MapAndWrite::SKIPPED_CHANGES, $mapAndWriteResult->getStatusCode());
    self::assertEquals(SyncEligibility::ELIGIBLE, $syncEligibleResult->getStatusCode());
    self::assertEquals(Sync::SUCCESS, $syncResult->getStatusCode());
    self::assertEquals(LocalRemotePair::class, get_class($savedMatch));
    self::assertNotNull($pair->getRemoteObject()->getId());

    self::assertEquals('testMatchAndSyncIfEligible_FromLocal',
      $pair->getRemoteObject()->name->get());
  }

  public function testMatchAndSyncIfEligible_WriteNewTwin_FromRemote() {
    self::markTestIncomplete();
    $syncer = self::$syncer;
    $remoteTag = new \Civi\Osdi\ActionNetwork\Object\Tag(self::$remoteSystem);
    $remoteTag->name->set('testMatchAndSyncIfEligible_FromRemote');
    $remoteTag->save();

    // FIRST SYNC
    $pair = $syncer->matchAndSyncIfEligible($remoteTag);
    $resultStack = $pair->getResultStack();
    $fetchFindMatchResult = $resultStack->getLastOfType(FetchOldOrFindNewMatch::class);
    $matchResult = $resultStack->getLastOfType(\Civi\Osdi\Result\MatchResult::class);
    $mapAndWriteResult = $resultStack->getLastOfType(MapAndWrite::class);
    $syncEligibleResult = $resultStack->getLastOfType(SyncEligibility::class);
    $syncResult = $resultStack->getLastOfType(Sync::class);
    $savedMatch = $syncResult->getState();

    self::assertEquals(FetchOldOrFindNewMatch::NO_MATCH_FOUND, $fetchFindMatchResult->getStatusCode());
    self::assertNull($matchResult->getMessage());
    self::assertEquals(MapAndWrite::WROTE_NEW, $mapAndWriteResult->getStatusCode());
    self::assertEquals(SyncEligibility::ELIGIBLE, $syncEligibleResult->getStatusCode());
    self::assertEquals(Sync::SUCCESS, $syncResult->getStatusCode());
    self::assertEquals(LocalRemotePair::class, get_class($savedMatch));
    self::assertNotNull($pair->getLocalObject()->getId());

    self::assertEquals('testMatchAndSyncIfEligible_FromRemote',
      $pair->getLocalObject()->name->get());

    // SECOND SYNC
    $pair = $syncer->matchAndSyncIfEligible($remoteTag);
    $resultStack = $pair->getResultStack();
    $fetchFindMatchResult = $resultStack->getLastOfType(FetchOldOrFindNewMatch::class);
    $mapAndWriteResult = $resultStack->getLastOfType(MapAndWrite::class);
    $syncEligibleResult = $resultStack->getLastOfType(SyncEligibility::class);
    $syncResult = $resultStack->getLastOfType(Sync::class);
    $savedMatch = $syncResult->getState();

    self::assertEquals(FetchOldOrFindNewMatch::FETCHED_SAVED_MATCH, $fetchFindMatchResult->getStatusCode());
    self::assertEquals(MapAndWrite::NO_CHANGES_TO_WRITE, $mapAndWriteResult->getStatusCode());
    self::assertEquals(SyncEligibility::ELIGIBLE, $syncEligibleResult->getStatusCode());
    self::assertEquals(Sync::SUCCESS, $syncResult->getStatusCode());
    self::assertEquals(LocalRemotePair::class, get_class($savedMatch));
    self::assertNotNull($pair->getLocalObject()->getId());

    self::assertEquals('testMatchAndSyncIfEligible_FromRemote',
      $pair->getLocalObject()->name->get());

    // THIRD SYNC
    $remoteTag = new \Civi\Osdi\ActionNetwork\Object\Tag(self::$remoteSystem);
    $remoteTag->name->set('testMatchAndSyncIfEligible_FromRemote (new name)');
    $remoteTag->save();

    /** @var \Civi\Osdi\LocalRemotePair $savedMatch */
    $savedMatch->setRemoteObject($remoteTag);
    $syncer->saveMatch($savedMatch);

    $pair = $syncer->matchAndSyncIfEligible($remoteTag);
    $resultStack = $pair->getResultStack();
    $fetchFindMatchResult = $resultStack->getLastOfType(FetchOldOrFindNewMatch::class);
    $mapAndWriteResult = $resultStack->getLastOfType(MapAndWrite::class);
    $syncEligibleResult = $resultStack->getLastOfType(SyncEligibility::class);
    $syncResult = $resultStack->getLastOfType(Sync::class);
    $savedMatch = $syncResult->getState();

    self::assertEquals(FetchOldOrFindNewMatch::FETCHED_SAVED_MATCH, $fetchFindMatchResult->getStatusCode());
    self::assertEquals(MapAndWrite::WROTE_CHANGES, $mapAndWriteResult->getStatusCode());
    self::assertEquals(SyncEligibility::ELIGIBLE, $syncEligibleResult->getStatusCode());
    self::assertEquals(Sync::SUCCESS, $syncResult->getStatusCode());
    self::assertEquals(LocalRemotePair::class, get_class($savedMatch));
    self::assertNotNull($pair->getLocalObject()->getId());

    self::assertEquals('testMatchAndSyncIfEligible_FromRemote (new name)',
      $pair->getLocalObject()->name->get());
  }

  public function testMatchAndSyncIfEligible_MatchExistingTwin_FromRemote() {
    self::markTestIncomplete();
    $syncer = self::$syncer;

    $localTag = new \Civi\Osdi\LocalObject\TagBasic();
    $localTag->name->set('testMatchAndSyncIfEligible_MatchyMatchy');
    $localTag->save();

    $remoteTag = new \Civi\Osdi\ActionNetwork\Object\Tag(self::$remoteSystem);
    $remoteTag->name->set('testMatchAndSyncIfEligible_MatchyMatchy');
    $remoteTag->save();

    // FIRST SYNC
    $pair = $syncer->matchAndSyncIfEligible($remoteTag);
    $resultStack = $pair->getResultStack();
    $fetchFindMatchResult = $resultStack->getLastOfType(FetchOldOrFindNewMatch::class);
    $matchResult = $resultStack->getLastOfType(\Civi\Osdi\Result\MatchResult::class);
    $mapAndWriteResult = $resultStack->getLastOfType(MapAndWrite::class);
    $syncEligibleResult = $resultStack->getLastOfType(SyncEligibility::class);
    $syncResult = $resultStack->getLastOfType(Sync::class);
    $savedMatch = $syncResult->getState();

    self::assertEquals(FetchOldOrFindNewMatch::FOUND_NEW_MATCH, $fetchFindMatchResult->getStatusCode());
    self::assertEquals(\Civi\Osdi\Result\MatchResult::FOUND_MATCH, $matchResult->getStatusCode());
    self::assertEquals(MapAndWrite::NO_CHANGES_TO_WRITE, $mapAndWriteResult->getStatusCode());
    self::assertEquals(SyncEligibility::ELIGIBLE, $syncEligibleResult->getStatusCode());
    self::assertEquals(Sync::SUCCESS, $syncResult->getStatusCode());
    self::assertEquals(LocalRemotePair::class, get_class($savedMatch));
    self::assertNotNull($pair->getLocalObject()->getId());

    self::assertEquals('testMatchAndSyncIfEligible_MatchyMatchy',
      $pair->getLocalObject()->name->get());

    // SECOND SYNC
    $pair = $syncer->matchAndSyncIfEligible($remoteTag);
    $resultStack = $pair->getResultStack();
    $fetchFindMatchResult = $resultStack->getLastOfType(FetchOldOrFindNewMatch::class);
    $mapAndWriteResult = $resultStack->getLastOfType(MapAndWrite::class);
    $syncEligibleResult = $resultStack->getLastOfType(SyncEligibility::class);
    $syncResult = $resultStack->getLastOfType(Sync::class);
    $savedMatch = $syncResult->getState();

    self::assertEquals(FetchOldOrFindNewMatch::FETCHED_SAVED_MATCH, $fetchFindMatchResult->getStatusCode());
    self::assertEquals(MapAndWrite::NO_CHANGES_TO_WRITE, $mapAndWriteResult->getStatusCode());
    self::assertEquals(SyncEligibility::ELIGIBLE, $syncEligibleResult->getStatusCode());
    self::assertEquals(Sync::SUCCESS, $syncResult->getStatusCode());
    self::assertEquals(LocalRemotePair::class, get_class($savedMatch));
    self::assertNotNull($pair->getLocalObject()->getId());

    self::assertEquals('testMatchAndSyncIfEligible_MatchyMatchy',
      $pair->getLocalObject()->name->get());
  }

}
