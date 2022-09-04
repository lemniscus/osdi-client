<?php

use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Result\FetchOldOrFindNewMatch;
use Civi\Osdi\Result\MapAndWrite;
use Civi\Osdi\Result\Sync;
use Civi\Osdi\Result\SyncEligibility;

/**
 * @group headless
 */
class CRM_OSDI_ActionNetwork_SingleSyncer_Tag_BasicTest extends PHPUnit\Framework\TestCase implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\TransactionalInterface {

  private static \Civi\Osdi\ActionNetwork\SingleSyncer\TagBasic $syncer;

  private static \Civi\Osdi\ActionNetwork\RemoteSystem $remoteSystem;

  private array $createdLocalTagIds = [];

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public static function setUpBeforeClass(): void {
    self::$remoteSystem = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
    $syncer = self::makeNewSyncer();
    self::$syncer = $syncer;

    Civi::cache('long')->delete('osdi-client:tag-match');
  }

  protected function setUp(): void {
    CRM_OSDI_FixtureHttpClient::resetHistory();
  }

  protected function tearDown(): void {
    while ($id = array_pop($this->createdLocalTagIds)) {
      \Civi\Api4\Tag::delete(FALSE)
        ->addWhere('id', '=', $id)
        ->execute();
    }
  }

  public static function tearDownAfterClass(): void {
    Civi::cache('long')->delete('osdi-client:tag-match');
  }

  private static function makeNewSyncer(): \Civi\Osdi\ActionNetwork\SingleSyncer\TagBasic {
    $syncer = new \Civi\Osdi\ActionNetwork\SingleSyncer\TagBasic(self::$remoteSystem);
    $syncer->setMapper(new \Civi\Osdi\ActionNetwork\Mapper\TagBasic());
    $syncer->setMatcher(new \Civi\Osdi\ActionNetwork\Matcher\TagBasic(self::$remoteSystem));
    return $syncer;
  }

  public function testOneWayMapAndWrite_NoTwinGiven_FromLocal() {
    $syncer = self::$syncer;
    $localTag = new \Civi\Osdi\LocalObject\TagBasic();
    $localTag->name->set('testOneWayMapAndWrite_NoTwinGiven');
    $pair = $syncer->toLocalRemotePair($localTag);
    $pair->setOrigin($pair::ORIGIN_LOCAL);

    self::assertNull($pair->getRemoteObject());

    $syncer->oneWayMapAndWrite($pair);
    $resultStack = $pair->getResultStack();
    $lastResult = $resultStack->last();

    self::assertFalse($resultStack->lastIsError());
    self::assertEquals(MapAndWrite::class, get_class($lastResult));

    self::assertEquals(MapAndWrite::WROTE_NEW,
      $lastResult->getStatusCode());

    self::assertNotNull($pair->getRemoteObject()->getId());

    self::assertEquals('testOneWayMapAndWrite_NoTwinGiven',
      $pair->getRemoteObject()->name->get());
  }

  public function testOneWayMapAndWrite_NoTwinGiven_FromRemote() {
    $syncer = self::$syncer;
    $remoteTag = new \Civi\Osdi\ActionNetwork\Object\Tag(self::$remoteSystem);
    $remoteTag->name->set('testOneWayMapAndWrite_NoTwinGiven');
    $pair = $syncer->toLocalRemotePair(NULL, $remoteTag);
    $pair->setOrigin($pair::ORIGIN_REMOTE);

    self::assertNull($pair->getLocalObject());

    $syncer->oneWayMapAndWrite($pair);
    $resultStack = $pair->getResultStack();
    $lastResult = $resultStack->last();

    self::assertFalse($resultStack->lastIsError());
    self::assertEquals(MapAndWrite::class, get_class($lastResult));

    self::assertEquals(MapAndWrite::WROTE_NEW,
      $lastResult->getStatusCode());

    self::assertNotNull($pair->getLocalObject()->getId());

    self::assertEquals('testOneWayMapAndWrite_NoTwinGiven',
      $pair->getLocalObject()->name->get());
  }

  public function testOneWayMapAndWrite_TwinGiven_FromRemote() {
    $syncer = self::$syncer;
    $remoteTag = new \Civi\Osdi\ActionNetwork\Object\Tag(self::$remoteSystem);
    $remoteTag->name->set('Abe');
    $localTag = new \Civi\Osdi\LocalObject\TagBasic();
    $localTag->name->set('Zev');
    $pair = $syncer->toLocalRemotePair($localTag, $remoteTag);
    $pair->setOrigin($pair::ORIGIN_REMOTE);

    $syncer->oneWayMapAndWrite($pair);
    $resultStack = $pair->getResultStack();
    $lastResult = $resultStack->last();

    self::assertFalse($resultStack->lastIsError());
    self::assertEquals(MapAndWrite::class, get_class($lastResult));

    self::assertEquals(MapAndWrite::WROTE_NEW,
      $lastResult->getStatusCode());

    self::assertNotNull($pair->getLocalObject()->getId());

    self::assertEquals('Abe',
      $pair->getLocalObject()->name->getAsLoaded());

    $remoteTag->name->set('Moe');
    $syncer->oneWayMapAndWrite($pair);
    self::assertEquals(MapAndWrite::WROTE_CHANGES,
      $pair->getResultStack()->last()->getStatusCode());
  }

  public function testOneWayMapAndWrite_TwinGiven_FromLocal_CanCreateButNotUpdate() {
    $syncer = self::$syncer;
    $remoteTag = new \Civi\Osdi\ActionNetwork\Object\Tag(self::$remoteSystem);
    $remoteTag->name->set('Abe');
    $localTag = new \Civi\Osdi\LocalObject\TagBasic();
    $localTag->name->set('Zev');
    $pair = $syncer->toLocalRemotePair($localTag, $remoteTag);
    $pair->setOrigin($pair::ORIGIN_LOCAL);

    $syncer->oneWayMapAndWrite($pair);
    $resultStack = $pair->getResultStack();
    $lastResult = $resultStack->last();

    self::assertFalse($resultStack->lastIsError());
    self::assertEquals(MapAndWrite::class, get_class($lastResult));

    self::assertEquals(MapAndWrite::WROTE_NEW,
      $lastResult->getStatusCode());

    self::assertNotNull($pair->getRemoteObject()->getId());

    self::assertEquals('Zev',
      $pair->getRemoteObject()->name->getAsLoaded());

    $localTag->name->set('Moe');
    $syncer->oneWayMapAndWrite($pair);
    self::assertEquals(MapAndWrite::SKIPPED_CHANGES,
      $pair->getResultStack()->last()->getStatusCode());
  }

  public function testMatchAndSyncIfEligible_WriteNewTwin_FromLocal() {
    $syncer = self::$syncer;
    $localTag = new \Civi\Osdi\LocalObject\TagBasic();
    $localTag->name->set('testMatchAndSyncIfEligible_FromLocal');
    $localTag->save();

    // FIRST SYNC
    $pair = $syncer->matchAndSyncIfEligible($localTag);
    $resultStack = $pair->getResultStack();
    $fetchFindMatchResult = $resultStack->getLastOfType(FetchOldOrFindNewMatch::class);
    $matchResult = $resultStack->getLastOfType(\Civi\Osdi\Result\MatchResult::class);
    $mapAndWriteResult = $resultStack->getLastOfType(MapAndWrite::class);
    $syncEligibleResult = $resultStack->getLastOfType(SyncEligibility::class);
    $syncResult = $resultStack->getLastOfType(Sync::class);
    $savedMatch = $syncResult->getState();

    self::assertEquals(FetchOldOrFindNewMatch::NO_MATCH_FOUND, $fetchFindMatchResult->getStatusCode());

    self::assertEquals('Finding tags on Action Network is not implemented',
      $matchResult->getMessage());

    self::assertEquals(MapAndWrite::WROTE_NEW, $mapAndWriteResult->getStatusCode());
    self::assertEquals(SyncEligibility::ELIGIBLE, $syncEligibleResult->getStatusCode());
    self::assertEquals(Sync::SUCCESS, $syncResult->getStatusCode());
    self::assertEquals(LocalRemotePair::class, get_class($savedMatch));
    self::assertNotNull($pair->getRemoteObject()->getId());

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

  public function testSavedMatchPersistence() {
    $remoteTag = new \Civi\Osdi\ActionNetwork\Object\Tag(self::$remoteSystem);
    $remoteTag->name->set('testSavedMatchPersistence');
    $remoteTag->save();

    // SYNC WITH FIRST SYNCER
    $syncerOne = self::makeNewSyncer();
    $pair = $syncerOne->matchAndSyncIfEligible($remoteTag);
    $savedMatch = $pair->getResultStack()->getLastOfType(Sync::class)->getState();

    self::assertEquals(LocalRemotePair::class, get_class($savedMatch));

    // SYNC WITH SECOND SYNCER
    $syncerTwo = self::makeNewSyncer();
    $pair = $syncerTwo->matchAndSyncIfEligible($remoteTag);
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

    self::assertEquals('testSavedMatchPersistence',
      $pair->getLocalObject()->name->get());
  }

}
