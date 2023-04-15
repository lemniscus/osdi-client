<?php

namespace Civi\Osdi\ActionNetwork\BatchSyncer;

use Civi;
use Civi\Api4\EntityTag;
use Civi\Osdi\Container;
use CRM_OSDI_ActionNetwork_TestUtils;
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
    self::$remoteSystem = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
    self::$syncer = self::makeNewSyncer();
    parent::setUp();
  }

  private static function makeNewSyncer(): TaggingBasic {
    return new TaggingBasic(self::makeNewSingleSyncer());
  }

  private static function makeNewSingleSyncer(): \Civi\Osdi\ActionNetwork\SingleSyncer\TaggingBasic {
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

  /**
   * @return array{0: \Civi\Osdi\LocalObject\TagBasic[], 1: \Civi\Osdi\ActionNetwork\Object\Tag[]}
   */
  private function makeSameTagsOnBothSides(): array {
    $remoteTags = $localTags = [];
    foreach (['a', 'b'] as $index) {
      $tagName = "test tagging sync $index";

      $remoteTag = new \Civi\Osdi\ActionNetwork\Object\Tag(self::$remoteSystem);
      $remoteTag->name->set($tagName);
      $remoteTag->save();
      $remoteTags[$index] = $remoteTag;

      $localTag = new \Civi\Osdi\LocalObject\TagBasic();
      $localTag->name->set($tagName);
      $localTag->save();
      $localTags[$index] = $localTag;
    }
    return [$localTags, $remoteTags];
  }

  private function makeSamePersonOnBothSides(string $index): array {
    $email = "taggingtest$index@test.net";
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
  }

  public function testBatchSyncFromRemote() {
    [$localTags, $remoteTags] = $this->makeSameTagsOnBothSides();

    foreach ($remoteTags as $remoteTag) {
      /** @var \Civi\Osdi\ActionNetwork\RemoteFindResult $remoteTaggingCollection */
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

      [$localPerson, $remotePerson] = $this->makeSamePersonOnBothSides($i);
      $localPeople[$i] = $localPerson;

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

    self::$syncer->batchSyncFromRemote();

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

}
