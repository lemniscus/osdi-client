<?php

namespace Civi\Osdi\ActionNetwork\Object;

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use CRM_OSDI_ActionNetwork_TestUtils;
use CRM_OSDI_FixtureHttpClient;

/**
 * @group headless
 */
class TaggingTest extends \PHPUnit\Framework\TestCase implements
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

  public function testCreate_Save() {
    // CREATE
    $tag = new Tag(self::$system);
    $tag->name->set('Tagalina');

    $person = new Person(self::$system);
    $person->emailAddress->set('tagteam@dio.de');

    $tagging = new Tagging(self::$system);
    $tagging->setTag($tag);
    $tagging->setPerson($person);

    self::expectException(\Civi\Osdi\Exception\InvalidArgumentException::class);
    $tagging->save();

    $person->save();

    self::expectException(\Civi\Osdi\Exception\InvalidArgumentException::class);
    $tagging->save();

    $tag->save();
    $tagging->save();

    self::assertNotNull($tagging->getId());
    self::assertEquals($person, $tagging->getPerson());
    self::assertEquals($tag, $tagging->getTag());
  }

  public function testCreate_Save_ReFetch_GetComponents() {
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

    self::assertNotEquals($tag, $taggingFromRemote->getTag());
    self::assertEquals($tag->getId(), $taggingFromRemote->getTag()->getId());
    self::assertNotEquals($person, $taggingFromRemote->getPerson());
    self::assertEquals($person->getId(), $taggingFromRemote->getPerson()
      ->getId());
  }

  public function testCreate_TrySave() {
    // CREATE
    $tag = new Tag(self::$system);
    $tag->name->set('Tagalina');

    $person = new Person(self::$system);
    $person->emailAddress->set('tagteam@dio.de');

    $tagging = new Tagging(self::$system);
    $tagging->setTag($tag);
    $tagging->setPerson($person);
    $result = $tagging->trySave();

    self::assertTrue($result->isError());

    $person->save();
    $tag->save();
    $result = $tagging->trySave();

    self::assertFalse($result->isError());
  }

  public function testCreate_Delete() {
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
    self::assertStringStartsWith('http', $savedTaggingUrl);

    // DELETE
    $tagging->delete();
    $this->expectException(\Civi\Osdi\Exception\EmptyResultException::class);
    self::$system->fetchObjectByUrl('osdi:taggings', $savedTaggingUrl);
  }

  public function testisAltered() {
    $tag = new Tag(self::$system);
    $tag->name->set('Tagalina');
    $tag->save();

    $person = new Person(self::$system);
    $person->emailAddress->set('tagteam@dio.de');
    $person->save();

    $tagging = new Tagging(self::$system);
    self::assertFalse($tagging->isAltered());

    $tagging->setTag($tag);
    self::assertTrue($tagging->isAltered());

    $tagging->setPerson($person);
    self::assertTrue($tagging->isAltered());

    $tagging->save();
    self::assertFalse($tagging->isAltered());
  }

}
