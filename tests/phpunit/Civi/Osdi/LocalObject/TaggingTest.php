<?php

namespace Civi\Osdi\LocalObject;

use Civi\Test\HeadlessInterface;
use Civi\Core\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class TaggingTest extends \PHPUnit\Framework\TestCase implements
    HeadlessInterface,
    HookInterface,
    TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public function testGetCiviEntity() {
    $tagging = new TaggingBasic();
    self::assertEquals('EntityTag', $tagging::getCiviEntityName());
  }

  public function testCreate_Save() {
    // CREATE
    $tag = new TagBasic();
    $tag->name->set('Tagalina');

    $person = new PersonBasic();
    $person->emailEmail->set('tagteam@dio.de');

    $tagging = new TaggingBasic();
    $tagging->setTag($tag);
    $tagging->setPerson($person);

    try {
      $tagging->save();
      self::fail('Tagging should not be able to be saved until its person has an id');
    }
    catch (\Throwable $e) {
      self::assertEquals(\Civi\Osdi\Exception\InvalidArgumentException::class,
        get_class($e));
    }

    $person->save();

    try {
      $tagging->save();
      self::fail('Tagging should not be able to be saved until its tag has an id');
    }
    catch (\Throwable $e) {
      self::assertEquals(\Civi\Osdi\Exception\InvalidArgumentException::class,
        get_class($e));
    }

    $tag->save();
    $tagging->save();

    self::assertNotNull($tagging->getId());
    self::assertEquals($person, $tagging->getPerson());
    self::assertEquals($tag, $tagging->getTag());
  }

  public function testCreate_Save_ReFetch_GetComponents() {
    // CREATE
    $tag = new TagBasic();
    $tag->name->set('Tagalina');
    $tag->save();

    $person = new PersonBasic();
    $person->emailEmail->set('tagteam@dio.de');
    $person->save();

    $tagging = new TaggingBasic();
    $tagging->setTag($tag);
    $tagging->setPerson($person);
    $tagging->save();

    self::assertNotNull($tagging->getId());

    // FETCH COMPONENTS

    $taggingFromDatabase = TaggingBasic::fromId($tagging->getId());

    self::assertNotEquals($tag, $taggingFromDatabase->getTag());
    self::assertEquals($tag->getId(), $taggingFromDatabase->getTag()->getId());
    self::assertNotEquals($person, $taggingFromDatabase->getPerson());
    self::assertEquals($person->getId(), $taggingFromDatabase->getPerson()
      ->getId());
  }

  public function testCreate_TrySave() {
    // CREATE
    $tag = new TagBasic();
    $tag->name->set('Tagalina');

    $person = new PersonBasic();
    $person->emailEmail->set('tagteam@dio.de');

    $tagging = new TaggingBasic();
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
    $tag = new TagBasic();
    $tag->name->set('Tagalina');
    $tag->save();

    $person = new PersonBasic();
    $person->emailEmail->set('tagteam@dio.de');
    $person->save();

    $tagging = new TaggingBasic();
    $tagging->setTag($tag);
    $tagging->setPerson($person);
    $tagging->save();

    // DELETE
    $taggingId = $tagging->getId();
    $tagging->delete();
    $this->expectException(\Civi\Osdi\Exception\InvalidArgumentException::class);
    TaggingBasic::fromId($taggingId);
  }

  public function testisAltered() {
    $tag = new TagBasic();
    $tag->name->set('Tagalina');
    $tag->save();

    $person = new PersonBasic();
    $person->emailEmail->set('tagteam@dio.de');
    $person->save();

    $tagging = new TaggingBasic();
    self::assertFalse($tagging->isAltered());

    $tagging->setTag($tag);
    self::assertTrue($tagging->isAltered());

    $tagging->setPerson($person);
    self::assertTrue($tagging->isAltered());

    $tagging->save();
    self::assertFalse($tagging->isAltered());
  }

}
