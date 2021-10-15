<?php

use Civi\Osdi\ActionNetwork\Object\Person as ANPerson;
use CRM_OSDI_Fixture_PersonMatching as PersonMatchFixture;

/**
 * @group headless
 */
class CRM_OSDI_ActionNetwork_TagSyncerTest extends PHPUnit\Framework\TestCase implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\TransactionalInterface {

  private static array $syncProfile;

  private static \Civi\Osdi\ActionNetwork\Syncer\Tag $syncer;

  private static \Civi\Osdi\ActionNetwork\RemoteSystem $remoteSystem;

  private array $createdRemoteTags = [];

  private array $createdLocalTagIds = [];

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public static function setUpBeforeClass(): void {
    self::$syncProfile = CRM_OSDI_ActionNetwork_TestUtils::createSyncProfile();
    self::$remoteSystem = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();

    self::$syncer = new \Civi\Osdi\ActionNetwork\Syncer\Tag(self::$remoteSystem);
    self::$syncer->setSyncProfile(self::$syncProfile);

    Civi::cache('long')->delete('osdi-client:tag-match');
  }

  protected function setUp(): void {
    CRM_OSDI_FixtureHttpClient::resetHistory();
  }

  protected function tearDown(): void {
    while ($entity = array_pop($this->createdRemoteTags)) {
      self::$remoteSystem->delete($entity);
    }

    while ($id = array_pop($this->createdLocalTagIds)) {
      \Civi\Api4\Tag::delete(FALSE)
        ->addWhere('id', '=', $id)
        ->execute();
    }
  }

  public static function tearDownAfterClass(): void {
    Civi::settings()->revert('osdi-client:tag-match');
  }

  public function testSyncNewOutgoingSuccess() {
    // SETUP

    $name = 'Comms: This is a Test';
    $localTag = \Civi\Api4\Tag::create(FALSE)
      ->addValue('name', $name)
      ->addValue('used_for:name', ['Contact'])
      ->execute()->single();
    $this->createdLocalTagIds[] = $localTag['id'];

    // PRE-ASSERTS

    $existingMatch = self::$syncer
      ->getSavedMatch(self::$syncProfile['id'], 'Local:Tag:Id', $localTag['id']);

    self::assertCount(0, $existingMatch);

    // TEST PROPER

    $result = self::$syncer->oneWaySync('Local:Tag:Id', $localTag['id']);

    self::assertEquals(\Civi\Osdi\SyncResult::class, get_class($result));
    self::assertEquals(\Civi\Osdi\SyncResult::SUCCESS, $result->getStatus());

    $remoteTag = $result->getRemoteObject();

    self::assertEquals(\Civi\Osdi\ActionNetwork\Object\Tag::class,
      get_class($remoteTag));
    self::assertEquals($name, $remoteTag->get('name'));
    self::assertNotNull($remoteTag->getId());

    $existingMatch = self::$syncer
      ->getSavedMatch(self::$syncProfile['id'], 'Local:Tag:Id', $localTag['id']);

    self::assertEquals($name, $existingMatch['remote']['name']);
    self::assertEquals($remoteTag->getId(), $existingMatch['remote']['id']);
  }

  public function testSyncNewOutgoingFailure() {
    $result = self::$syncer->oneWaySync('Local:Tag:Id', -99);

    self::assertEquals(\Civi\Osdi\SyncResult::class, get_class($result));
    self::assertEquals(\Civi\Osdi\SyncResult::ERROR, $result->getStatus());
  }

}
