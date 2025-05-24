<?php

namespace Civi\Osdi\ActionNetwork\BatchSyncer;

use Civi;
use Civi\Api4\EntityTag;
use Civi\Osdi\Logger;
use Civi\OsdiClient;
use OsdiClient\ActionNetwork\TestUtils;
use PHPUnit;

/**
 * @group headless
 */
class TaggingBasicTest extends PHPUnit\Framework\TestCase implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\TransactionalInterface {

  private static \Civi\Osdi\ActionNetwork\BatchSyncer\TaggingBasic $syncer;

  private static \Civi\Osdi\ActionNetwork\RemoteSystem $remoteSystem;

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  protected function setUp(): void {
    self::$remoteSystem = TestUtils::createRemoteSystem();
    self::$syncer = self::makeNewSyncer();
    parent::setUp();
  }

  public function testBatchMirror() {
    [$localTags, $remoteTags] = $this->makeSameTagsOnBothSides();
    $remoteTagNamesById = [];

    foreach ($remoteTags as $remoteTag) {
      /** @var \Civi\Osdi\ActionNetwork\RemoteFindResult $remoteTaggingCollection */
      $remoteTagNamesById[$remoteTag->getId()] = $remoteTag->name->get();
      $remoteTaggingCollection = $remoteTag->getTaggings()->loadAll();
      foreach ($remoteTaggingCollection as $remoteTagging) {
        $remoteTagging->delete();
      }
    }

    $plan = [
      1 => [
        'rem' => ['a'],
        'loc' => ['a'],
      ],
      2 => [
        'rem' => ['a'],
        'loc' => ['b'],
      ],
      3 => [
        'rem' => ['a'],
        'loc' => ['a', 'b'],
      ],
      4 => [
        'rem' => ['a'],
        'loc' => [],
      ],
      // 5-24 will be the same as 4
      25 => [
        'rem' => ['a', 'b'],
        'loc' => [],
      ],
      26 => [
        'rem' => ['a', 'b'],
        'loc' => ['a'],
      ],
      27 => [
        'rem' => ['a', 'b'],
        'loc' => ['b'],
      ],
      28 => [
        'rem' => [],
        'loc' => ['a'],
      ],
      29 => [
        'rem' => [],
        'loc' => ['a', 'b'],
      ],
      30 => [
        'rem' => ['b'],
        'loc' => [],
      ],
      31 => [
        'rem' => [],
        'loc' => [],
      ],
    ];

    for ($i = 1; $i <= 28; $i++) {
      if (array_key_exists($i, $plan)) {
        $tagNamesBeforeSync = $plan[$i];
      }

      [$localPerson, $remotePerson] = $this->makeSamePersonOnBothSides($i);
      $localPeople[$i] = $localPerson;
      $remotePeople[$i] = $remotePerson;

      foreach ($tagNamesBeforeSync['rem'] as $tagLetter) {
        $remoteTagging = new Civi\Osdi\ActionNetwork\Object\Tagging(self::$remoteSystem);
        $remoteTagging->setPerson($remotePerson);
        $remoteTagging->setTag($remoteTags[$tagLetter]);
        $remoteTagging->save();
      }
      foreach ($tagNamesBeforeSync['loc'] as $tagLetter) {
        $localTagging = new Civi\Osdi\LocalObject\TaggingBasic();
        $localTagging->setPerson($localPerson);
        $localTagging->setTag($localTags[$tagLetter]);
        $localTagging->save();
      }
    }

    self::$syncer->batchTwoWayMirror();

    for ($i = 1; $i <= 28; $i++) {
      if (array_key_exists($i, $plan)) {
        $tagNamesAfterSync =
          array_unique(array_merge($plan[$i]['rem'], $plan[$i]['loc']));
        sort($tagNamesAfterSync);
      }

      $expectedLocalTaggings[$i] = $tagNamesAfterSync;
      $expectedRemoteTaggings[$i] = $tagNamesAfterSync;

      $localPerson = $localPeople[$i];

      $localPersonTagNames = EntityTag::get(FALSE)
        ->addWhere('entity_table', '=', 'civicrm_contact')
        ->addWhere('entity_id', '=', $localPerson->getId())
        ->addSelect('tag_id:name')
        ->addOrderBy('tag_id:name')
        ->execute()->column('tag_id:name');

      foreach ($localPersonTagNames as $key => $val) {
        $localPersonTagNames[$key] = substr($val, -1);
      }

      $actualLocalTaggings[$i] = $localPersonTagNames;

      $remotePerson = $remotePeople[$i];
      $remoteTaggingCollection = $remotePerson->getTaggings()->loadAll();
      $tagNamesBeforeSync = [];

      foreach ($remoteTaggingCollection as $remoteTagging) {
        $remoteTagName = $remoteTagNamesById[$remoteTagging->getTag()->getId()];
        $tagNamesBeforeSync[] = substr($remoteTagName, -1);
      }

      sort($tagNamesBeforeSync);
      $actualRemoteTaggings[$i] = $tagNamesBeforeSync;
    }

    self::assertEquals($expectedLocalTaggings, $actualLocalTaggings);
    self::assertEquals($expectedRemoteTaggings, $actualRemoteTaggings);
  }

  public function testBatchSyncFromANDoesNotRunConcurrently() {
    Civi::settings()->add([
      'osdiClient.syncJobProcessId' => getmypid(),
      'osdiClient.syncJobEndTime' => NULL,
    ]);

    $singleSyncer = \Civi\OsdiClient::container()->getSingle('SingleSyncer', 'Tagging', self::$remoteSystem);
    /** @var \Civi\Osdi\ActionNetwork\BatchSyncer\TaggingBasic $batchSyncer */
    $batchSyncer = \Civi\OsdiClient::container()->getSingle('BatchSyncer', 'Tagging', $singleSyncer);

    $taggingCount = $batchSyncer->batchSyncFromRemote();
    self::assertNull($taggingCount);
    self::assertNull(Civi::settings()->get('osdiClient.syncJobEndTime'));

    self::assertFalse(posix_getsid(9999999999999));
    \Civi::settings()->set('osdiClient.syncJobProcessId', 9999999999999);

    $taggingCount = $batchSyncer->batchSyncFromRemote();
    self::assertNotNull($taggingCount);
    self::assertNotNull(Civi::settings()->get('osdiClient.syncJobEndTime'));
  }

  public function testBatchSyncFromRemote() {
    [$plan, $localPeople, $remotePeople] = $this->setUpBatchSyncFixture();
    self::$syncer->batchSyncFromRemote();
    $this->assertBatchSyncFromRemote($plan, $localPeople);
  }

  public function testBatchSyncFromRemoteViaApi() {
    [$plan, $localPeople, $remotePeople] = $this->setUpBatchSyncFixture();
    $syncProfileId = OsdiClient::container()->getSyncProfileId();

    $result = civicrm_api3('Job', 'osdiclientbatchsynctaggings',
      ['debug' => 1, 'origin' => 'remote', 'sync_profile_id' => $syncProfileId]);

    $this->assertBatchSyncFromRemote($plan, $localPeople);
  }

  private function assertBatchSyncFromRemote(array $plan, array $localPeople): void {
    for ($i = 1; $i <= 28; $i++) {
      if (array_key_exists($i, $plan)) {
        $tagNamesBeforeSync = $plan[$i]['rem'];
      }

      $expectedLocalTaggings[$i] = $tagNamesBeforeSync;
      $localPerson = $localPeople[$i];

      $localPersonTagNames = EntityTag::get(FALSE)
        ->addWhere('entity_table', '=', 'civicrm_contact')
        ->addWhere('entity_id', '=', $localPerson->getId())
        ->addSelect('tag_id:name')
        ->addOrderBy('tag_id:name')
        ->execute()->column('tag_id:name');

      foreach ($localPersonTagNames as $key => $val) {
        $localPersonTagNames[$key] = substr($val, -1);
      }

      $actualLocalTaggings[$i] = $localPersonTagNames;

    }

    self::assertEquals($expectedLocalTaggings, $actualLocalTaggings);
  }

  private static function makeNewSyncer(): TaggingBasic {
    return Civi\OsdiClient::container()->make('BatchSyncer', 'Tagging');
  }

  private function makeSamePersonOnBothSides(string $index): array {
    $email = "test_tagging_sync_$index@test.net";
    $givenName = "Test Tagging Sync $index";

    $remotePerson = new Civi\Osdi\ActionNetwork\Object\Person(self::$remoteSystem);
    $remotePerson->emailAddress->set($email);
    $remotePerson->givenName->set($givenName);
    $remotePerson->save();

    $localPerson = new \Civi\Osdi\LocalObject\PersonBasic();
    $localPerson->emailEmail->set($email);
    $localPerson->firstName->set($givenName);
    $localPerson->save();
    return [$localPerson, $remotePerson];
  }

  /**
   * @return array{0: \Civi\Osdi\LocalObject\TagBasic[], 1: \Civi\Osdi\ActionNetwork\Object\Tag[]}
   */
  private function makeSameTagsOnBothSides(): array {
    $remoteTags = $localTags = [];
    foreach (['a', 'b'] as $index) {
      $tagName = "test tagging sync $index";

      $remoteTag = new \Civi\Osdi\ActionNetwork\Object\Tag(self::$remoteSystem);
      $remoteTag->name->set($tagName);
      $remoteTag->save()->cache();
      $remoteTags[$index] = $remoteTag;

      $localTag = new \Civi\Osdi\LocalObject\TagBasic();
      $localTag->name->set($tagName);
      $localTag->save();
      $localTags[$index] = $localTag;
    }
    return [$localTags, $remoteTags];
  }

  private function setUpBatchSyncFixture(): array {
    Logger::logDebug("[TEST SETUP] creating tags");
    [$localTags, $remoteTags] = $this->makeSameTagsOnBothSides();

    foreach ($remoteTags as $remoteTag) {
      Logger::logDebug("[TEST SETUP] deleting all taggings of remote tag '{$remoteTag->name->get()}'");
      /** @var \Civi\Osdi\ActionNetwork\RemoteFindResult $remoteTaggingCollection */
      $remoteTaggingCollection = $remoteTag->getTaggings()->loadAll();
      foreach ($remoteTaggingCollection as $remoteTagging) {
        $remoteTagging->delete();
      }
    }

    /* this fixture is designed to test
     *   - no tags on origin, 1 or 2 on target
     *   - no tags on target, 1 or 2 on origin
     *   - single tag on each end, different
     *   - more tags on origin than on target, overlapping
     *   - more tags on target than on origin, overlapping
     *   - same tag on both ends
     *   - no tags on either end
     */

    $plan = [
      1 => [
        'rem' => ['a'],
        'loc' => ['a'],
      ],
      2 => [
        'rem' => ['a'],
        'loc' => ['b'],
      ],
      3 => [
        'rem' => ['a'],
        'loc' => ['a', 'b'],
      ],
      4 => [
        'rem' => ['a'],
        'loc' => [],
      ],
      // 5-23 will be the same as 4
      24 => [
        'rem' => [],
        'loc' => [],
      ],
      25 => [
        'rem' => ['a', 'b'],
        'loc' => [],
      ],
      26 => [
        'rem' => ['a', 'b'],
        'loc' => ['a'],
      ],
      27 => [
        'rem' => [],
        'loc' => ['a'],
      ],
      28 => [
        'rem' => [],
        'loc' => ['a', 'b'],
      ],
    ];

    for ($i = 1; $i <= 28; $i++) {
      if (array_key_exists($i, $plan)) {
        $tagNamesBeforeSync = $plan[$i];
      }

      Logger::logDebug("[TEST SETUP] writing local & remote persons $i and their taggings");
      [$localPerson, $remotePerson] = $this->makeSamePersonOnBothSides($i);
      $localPeople[$i] = $localPerson;
      $remotePeople[$i] = $remotePerson;

      foreach ($tagNamesBeforeSync['rem'] as $tagLetter) {
        $remoteTagging = new Civi\Osdi\ActionNetwork\Object\Tagging(self::$remoteSystem);
        $remoteTagging->setPerson($remotePerson);
        $remoteTagging->setTag($remoteTags[$tagLetter]);
        $remoteTagging->save();
      }
      foreach ($tagNamesBeforeSync['loc'] as $tagLetter) {
        $localTagging = new Civi\Osdi\LocalObject\TaggingBasic();
        $localTagging->setPerson($localPerson);
        $localTagging->setTag($localTags[$tagLetter]);
        $localTagging->save();
      }
    }
    return [$plan, $localPeople, $remotePeople];
  }

  public function testBatchSyncFromLocal() {
    [$plan, $localPeople, $remotePeople] = $this->setUpBatchSyncFixture();
    self::$syncer->batchSyncFromLocal();
    $this->assertBatchSyncFromLocal($plan, $remotePeople);
  }

  /**
   * @param array[] $plan
   * @param \Civi\Osdi\ActionNetwork\Object\Person[] $remotePeople
   */
  private function assertBatchSyncFromLocal(array $plan, array $remotePeople) {
    for ($i = 1; $i <= 28; $i++) {
      if (array_key_exists($i, $plan)) {
        $tagNamesBeforeSync = $plan[$i]['loc'];
      }

      $expectedRemoteTaggings[$i] = $tagNamesBeforeSync;
      $actualRemoteTaggings[$i] = [];

      $remotePerson = $remotePeople[$i];
      $remotePersonTaggings = $remotePerson->getTaggings();

      foreach ($remotePersonTaggings as $tagging) {
        $tag = $tagging->getTagUsingCache()->loadOnce();
        $actualRemoteTaggings[$i][] = substr($tag->name->get(), -1);
      }
    }

    self::assertEquals($expectedRemoteTaggings, $actualRemoteTaggings);
  }

}
