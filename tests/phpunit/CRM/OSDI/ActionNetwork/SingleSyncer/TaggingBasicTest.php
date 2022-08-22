<?php

use Civi\Osdi\ActionNetwork\SingleSyncer\Tagging as TaggingSyncer;
use CRM_OSDI_Fixture_PersonMatching as PersonMatchFixture;

/**
 * @group headless
 */
class CRM_OSDI_ActionNetwork_TaggingBasicTest extends PHPUnit\Framework\TestCase implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\TransactionalInterface {

  private static array $syncProfile;

  private static TaggingSyncer $syncer;

  private static \Civi\Osdi\ActionNetwork\RemoteSystem $remoteSystem;

  private array $createdRemoteObjects = [];

  private array $createdLocalObjectIds = [];

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public static function setUpBeforeClass(): void {
    PersonMatchFixture::$personClass = \Civi\Osdi\ActionNetwork\Object\Person::class;

    self::$syncProfile = CRM_OSDI_ActionNetwork_TestUtils::createSyncProfile();
    self::$remoteSystem = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    self::$syncer = new TaggingSyncer(self::$remoteSystem);
    self::$syncer->setSyncProfile(self::$syncProfile);

    Civi::cache('long')->delete('osdi-client:tag-match');
  }

  protected function setUp(): void {
    CRM_OSDI_FixtureHttpClient::resetHistory();
  }

  protected function tearDown(): void {
    // tags cannot be deleted
    foreach (['ppl', 'taggings'] as $type) {
      if (array_key_exists($type, $this->createdRemoteObjects)) {
        while ($object = array_pop($this->createdRemoteObjects[$type])) {
          $object->delete();
        }
      }
    }

    foreach (array_keys($this->createdLocalObjectIds) as $entityType) {
      while ($id = array_pop($this->createdLocalObjectIds[$entityType])) {
        civicrm_api4($entityType, 'delete', [
          'where' => [['id', '=', $id]],
          'checkPermissions' => FALSE,
        ]);
      }
    }

    parent::tearDown();
  }

  public static function tearDownAfterClass(): void {
    Civi::cache('long')->delete('osdi-client:tag-match');
    Civi::cache('short')->delete('osdi-client:tagging-match');
  }

  public function testSyncNewIncoming() {
    // SETUP

    [$originalRemotePerson, $contactId] =
      PersonMatchFixture::setUpExactlyOneMatchByEmailAndName(self::$remoteSystem);
    $this->createdRemoteObjects['ppl'][] = $originalRemotePerson;
    $this->createdLocalObjectIds['Contact'][] = $contactId;

    $tagName = 'Comms: This is a Test';
    $draftRemoteTag = new \Civi\Osdi\ActionNetwork\Object\TagOld(NULL, ['name' => $tagName]);
    $saveResult = self::$remoteSystem->trySave($draftRemoteTag);

    self::assertFalse($saveResult->isError());

    $this->createdRemoteObjects['tags'][] = $remoteTag = $saveResult->getReturnedObject();

    $draftRemoteTagging = new \Civi\Osdi\ActionNetwork\Object\Tagging(NULL, []);
    $draftRemoteTagging->setPerson($originalRemotePerson, self::$remoteSystem);
    $draftRemoteTagging->setTag($remoteTag, self::$remoteSystem);

    $saveResult = self::$remoteSystem->trySave($draftRemoteTagging);

    self::assertFalse($saveResult->isError());

    $this->createdRemoteObjects['taggings'][] = $remoteTagging = $saveResult->getReturnedObject();

    // TEST PROPER

    $result = self::$syncer->oneWaySync(TaggingSyncer::inputTypeActionNetworkTaggingObject, $remoteTagging);

    self::assertEquals(\Civi\Osdi\Result\Sync::class, get_class($result));
    self::assertEquals(\Civi\Osdi\Result\Sync::SUCCESS, $result->getStatusCode());

    $localEntityTag = $result->getLocalObject();
    $this->createdLocalObjectIds['EntityTag'][] = $localEntityTag['id'];
    $this->createdLocalObjectIds['Tag'][] = $localEntityTag['tag_id'];

    self::assertIsArray($localEntityTag);
    self::assertEquals($tagName, \Civi\Api4\Tag::get(FALSE)
      ->addWhere('id', '=', $localEntityTag['tag_id'])
      ->execute()->single()['name']
    );

    $localTaggingInDb = \Civi\Api4\EntityTag::get(FALSE)
      ->addWhere('id', '=', $localEntityTag['id'])
      ->execute()->single();
    self::assertNotEmpty($localTaggingInDb);

    $existingMatch = self::$syncer
      ->getSavedMatch(TaggingSyncer::inputTypeActionNetworkTaggingObject, $remoteTagging, self::$syncProfile['id']);

    self::assertEquals($localEntityTag['id'], $existingMatch['local']['id']);
  }

  public function testSyncNewOutgoingSuccess() {
    // SETUP

    [$originalRemotePerson, $contactId] =
      PersonMatchFixture::setUpExactlyOneMatchByEmailAndName(self::$remoteSystem);
    $this->createdRemoteObjects['ppl'][] = $originalRemotePerson;
    $this->createdLocalObjectIds['Contact'][] = $contactId;

    $tagName = 'Comms: This is a Test';
    $localTag = \Civi\Api4\Tag::create(FALSE)
      ->addValue('name', $tagName)
      ->addValue('used_for:name', ['Contact'])
      ->execute()->single();
    $this->createdLocalObjectIds['Tag'][] = $localTag['id'];

    $localEntityTag = \Civi\Api4\EntityTag::create(FALSE)
      ->addValue('entity_table', 'civicrm_contact')
      ->addValue('entity_id', $contactId)
      ->addValue('tag_id', $localTag['id'])
      ->execute()->single();
    $this->createdLocalObjectIds['EntityTag'][] = $localEntityTag['id'];

    // TEST PROPER

    $result = self::$syncer->oneWaySync(TaggingSyncer::inputTypeLocalEntityTagId, $localEntityTag['id']);

    self::assertEquals(\Civi\Osdi\Result\Sync::class, get_class($result));
    self::assertEquals(\Civi\Osdi\Result\Sync::SUCCESS, $result->getStatusCode());

    $remoteEntityTag = $result->getRemoteObject();

    self::assertEquals(\Civi\Osdi\ActionNetwork\Object\Tagging::class,
      get_class($remoteEntityTag));
    self::assertEquals($tagName, $remoteEntityTag->getTag()->get('name'));
    self::assertNotNull($remoteEntityTag->getId());

    $existingMatch = self::$syncer
      ->getSavedMatch(
        TaggingSyncer::inputTypeLocalEntityTagId,
        $localEntityTag['id'],
        self::$syncProfile['id']);

    self::assertEquals($remoteEntityTag->getId(), $existingMatch['remote']['id']);
  }

  public function testSyncNewOutgoingFailure() {
    $result = self::$syncer->oneWaySync(TaggingSyncer::inputTypeLocalEntityTagId, -99);

    self::assertEquals(\Civi\Osdi\Result\Sync::class, get_class($result));
    self::assertEquals(\Civi\Osdi\Result\Sync::ERROR, $result->getStatusCode());
  }

}
