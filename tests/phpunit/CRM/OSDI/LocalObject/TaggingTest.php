<?php

use Civi\Osdi\LocalObject\Person;
use Civi\Osdi\LocalObject\Tag;
use Civi\Osdi\LocalObject\Tagging;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class CRM_OSDI_LocalObject_TaggingTest extends \PHPUnit\Framework\TestCase implements
    HeadlessInterface,
    HookInterface,
    TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public function testGetCiviEntity() {
    $tagging = new Tagging();
    self::assertEquals('EntityTag', $tagging::getCiviEntityName());
  }

  public function testCreate_Save() {
    // CREATE
    $tag = new Tag();
    $tag->name->set('Tagalina');

    $person = new Person();
    $person->emailEmail->set('tagteam@dio.de');

    $tagging = new Tagging();
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
    $tag = new Tag();
    $tag->name->set('Tagalina');
    $tag->save();

    $person = new Person();
    $person->emailEmail->set('tagteam@dio.de');
    $person->save();

    $tagging = new Tagging();
    $tagging->setTag($tag);
    $tagging->setPerson($person);
    $tagging->save();

    self::assertNotNull($tagging->getId());

    // FETCH COMPONENTS

    $taggingFromDatabase = Tagging::fromId($tagging->getId());

    self::assertNotEquals($tag, $taggingFromDatabase->getTag());
    self::assertEquals($tag->getId(), $taggingFromDatabase->getTag()->getId());
    self::assertNotEquals($person, $taggingFromDatabase->getPerson());
    self::assertEquals($person->getId(), $taggingFromDatabase->getPerson()->getId());
  }

  public function testCreate_TrySave() {
    // CREATE
    $tag = new Tag();
    $tag->name->set('Tagalina');

    $person = new Person();
    $person->emailEmail->set('tagteam@dio.de');

    $tagging = new Tagging();
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
    $tag = new Tag();
    $tag->name->set('Tagalina');
    $tag->save();

    $person = new Person();
    $person->emailEmail->set('tagteam@dio.de');
    $person->save();

    $tagging = new Tagging();
    $tagging->setTag($tag);
    $tagging->setPerson($person);
    $tagging->save();

    // DELETE
    $taggingId = $tagging->getId();
    $tagging->delete();
    $this->expectException(\Civi\Osdi\Exception\InvalidArgumentException::class);
    Tagging::fromId($taggingId);
  }

  public function testisAltered() {
    $tag = new Tag();
    $tag->name->set('Tagalina');
    $tag->save();

    $person = new Person();
    $person->emailEmail->set('tagteam@dio.de');
    $person->save();

    $tagging = new Tagging();
    self::assertFalse($tagging->isAltered());

    $tagging->setTag($tag);
    self::assertTrue($tagging->isAltered());

    $tagging->setPerson($person);
    self::assertTrue($tagging->isAltered());

    $tagging->save();
    self::assertFalse($tagging->isAltered());
  }

}
