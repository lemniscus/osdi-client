<?php

namespace Civi\Osdi\ActionNetwork\Object;

use Civi\Core\HookInterface;
use Civi\Osdi\ActionNetwork\Object\Tag as Tag;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use OsdiClient\ActionNetwork\TestUtils;
use OsdiClient\FixtureHttpClient;

/**
 * @group headless
 */
class TagTest extends \PHPUnit\Framework\TestCase implements
    HeadlessInterface,
    HookInterface,
    TransactionalInterface {

  /**
   * @var array{Contact: array, Tag: array, EntityTag: array}
   */
  private static $createdEntities = [];

  /**
   * @var \Civi\Osdi\ActionNetwork\RemoteSystem
   */
  private $system;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    $this->system = TestUtils::createRemoteSystem();
    FixtureHttpClient::resetHistory();
    parent::setUp();
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  public static function tearDownAfterClass(): void {
    foreach (self::$createdEntities as $type => $ids) {
      foreach ($ids as $id) {
        civicrm_api4($type, 'delete', [
          'where' => [['id', '=', $id]],
          'checkPermissions' => FALSE,
        ]);
      }
    }

    parent::tearDownAfterClass();
  }

  public function testGetType() {
    $tag = new Tag($this->system);
    self::assertEquals('osdi:tags', $tag->getType());
  }

  public function testNewTagSetAndGetName() {
    $tag = new Tag($this->system);
    self::assertEmpty($tag->name->get());
    $tag->name->set('hello world');
    self::assertEquals('hello world', $tag->name->get());
  }

  public function testNewTagSetNameAndSaveAndFetchAndRead() {
    $tag = new Tag($this->system);

    self::assertEmpty($tag->getId());

    $tag->name->set('hello world');
    $id = $tag->save()->getId();

    self::assertNotEmpty($id);

    $reFetchedTag = new Tag($this->system);
    $reFetchedTag->setId($id);
    $reFetchedTag->load();

    self::assertEquals('hello world', $reFetchedTag->name->get());
  }

  public function testExistingTagCannotBeChanged() {
    $tag = new Tag($this->system);

    self::assertEmpty($tag->getId());

    $tag->name->set('hello world');
    $tag->save();

    self::assertNotEmpty($tag->getId());

    self::expectException(\Civi\Osdi\Exception\InvalidOperationException::class);
    $tag->name->set('a different value');
  }

  public function testTagDeleteIsProhibited() {
    $unsavedTag = new Tag($this->system);
    $unsavedTag->name->set('Tagalina');
    $savedTag = $unsavedTag->save();

    self::expectException(\Civi\Osdi\Exception\InvalidOperationException::class);
    $savedTag->delete();
  }

}
