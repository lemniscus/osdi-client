<?php

use Civi\Osdi\ActionNetwork\Object\Person;
use Civi\Osdi\ActionNetwork\Object\Tag;
use Civi\Osdi\ActionNetwork\Object\Tagging;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class CRM_OSDI_ActionNetwork_Object_TaggingTest extends \PHPUnit\Framework\TestCase implements
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
  public static $system;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public static function setUpBeforeClass(): void {
    self::$system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
    parent::setUpBeforeClass();
  }

  public function setUp(): void {
    CRM_OSDI_FixtureHttpClient::resetHistory();
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
    $tagging = new Tagging(self::$system);
    self::assertEquals('osdi:taggings', $tagging->getType());
  }

  public function testTaggingCreate_Save_ReFetch_GetComponents() {
    // CREATE
    $tag = new Tag(self::$system);
    $tag->name->set('Tagalina');
    $tag->save();

    $person = new Person(self::$system);
    $person->emailAddress->set('tagteam@dio.de');
    $person->save();

    $tagging = new Tagging(self::$system);
    $tagging->setTag($tag);
    $tagging->setPerson($person);
    $tagging->save();

    // FETCH COMPONENTS
    $taggingFromRemote = self::$system->fetchObjectByUrl($tagging->getType(), $tagging->getUrlForRead());

    $this->assertEquals($tag->getId(), $taggingFromRemote->getTag()->getId());
    $this->assertEquals($person->getId(), $taggingFromRemote->getPerson()->getId());
  }

  public function testTaggingCreate_Delete() {
    // CREATE
    $tag = new Tag(self::$system);
    $tag->name->set('Tagalina');
    $tag->save();

    $person = new Person(self::$system);
    $person->emailAddress->set('tagteam@dio.de');
    $person->save();

    $tagging = new Tagging(self::$system);
    $tagging->setTag($tag);
    $tagging->setPerson($person);
    $tagging->save();

    $savedTaggingUrl = $tagging->getUrlForRead();
    $this->assertStringStartsWith('http', $savedTaggingUrl);

    // DELETE
    $tagging->delete();
    $this->expectException(\Civi\Osdi\Exception\EmptyResultException::class);
    self::$system->fetchObjectByUrl('osdi:taggings', $savedTaggingUrl);
  }

}
