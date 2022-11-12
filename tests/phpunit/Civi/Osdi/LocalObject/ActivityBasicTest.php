<?php

namespace Civi\Osdi\LocalObject;

use Civi\Api4\Activity;
use Civi\Api4\ActivityContact;
use Civi\Test\HeadlessInterface;
use Civi\Core\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class ActivityBasicTest extends \PHPUnit\Framework\TestCase implements
    HeadlessInterface,
    HookInterface,
    TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public function testGetCiviEntity() {
    $activity = new ActivityBasic();
    self::assertEquals('Activity', $activity::getCiviEntityName());
  }

  public function testCreate_Save_RequiresSavedComponents() {
      // CREATE
    $unsavedPerson = new PersonBasic();
    $unsavedPerson->emailEmail->set('unsaved@dio.de');

    $savedPerson = new PersonBasic();
    $savedPerson->emailEmail->set('ihavebeensaved@dio.de');
    $savedPerson->save();

    $activity = new ActivityBasic();
    $activity->setSourcePerson($unsavedPerson);
    $activity->setTargets([$savedPerson]);
    $activity->activityDateTime->set('2020-03-04 05:06:07');
    $activity->activityTypeName->set('Bulk Email');
    $activity->subject->set('Test Activity');

    try {
      $activity->save();
      self::fail('Activity should not be able to be saved until its source person has an id');
    }
    catch (\Throwable $e) {
      self::assertEquals(\Civi\Osdi\Exception\InvalidArgumentException::class,
        get_class($e));
    }

    $activity->setSourcePerson($savedPerson);
    $activity->setTargets([$unsavedPerson]);

    try {
      $activity->save();
      self::fail('Activity should not be able to be saved until its target people have ids');
    }
    catch (\Civi\Osdi\Exception\InvalidArgumentException $e) {
      self::assertNotNull($e);
    }

    $activity->setTargets([$savedPerson]);

    $activity->save();
    self::assertNotNull($activity->getId());
  }

  public function testCreate_Save() {
    // CREATE
    $sourcePerson = new PersonBasic();
    $sourcePerson->emailEmail->set('activityTestSourcePerson@dio.de');
    $sourcePerson->firstName->set('Source Person');
    $sourcePerson->save();

    $targetPerson = new PersonBasic();
    $targetPerson->emailEmail->set('activityTestTargetPerson@dio.de');
    $sourcePerson->firstName->set('Target Person');
    $targetPerson->save();

    $activity = new ActivityBasic();
    $activity->setSourcePerson($sourcePerson);
    $activity->setTargets([$targetPerson]);
    $activity->activityDateTime->set('2020-03-04 05:06:07');
    $activity->activityTypeName->set('Bulk Email');
    $activity->subject->set('Test Activity');

    $activity->save();
    $activityId = $activity->getId();

    $activityFromCivi = Activity::get(FALSE)
      ->addWhere('id', '=', $activityId)
      ->addSelect('activity_date_time', 'activity_type_id:name', 'subject')
      ->execute()->single();

    $expected = [
      'activity_date_time' => '2020-03-04 05:06:07',
      'activity_type_id:name' => 'Bulk Email',
      'subject' => 'Test Activity',
    ];

    self::assertEquals($expected, $activityFromCivi);

    $activityContactsFromCivi = ActivityContact::get(FALSE)
      ->addWhere('activity_id', '=', $activityId)
      ->addSelect('record_type_id:name', 'contact_id.first_name')
      ->execute();

    $expected = [
      ['record_type_id:name' => 'Activity Source', 'contact_id.first_name' => 'Source Person'],
      ['record_type_id:name' => 'Activity Targets', 'contact_id.first_name' => 'Target Person'],
    ];

    self::assertEquals($expected, (array) $activityContactsFromCivi);
    //\CRM_Activity_DAO_ActivityContact::buildOptions('record_id', 'get');
  }

  public function testCreate_Save_ReFetch_GetComponents() {
    // CREATE
    $sourcePerson = new PersonBasic();
    $sourcePerson->emailEmail->set('activityTestSourcePerson@dio.de');
    $sourcePerson->firstName->set('Source Person');
    $sourcePerson->save();

    $targetPerson = new PersonBasic();
    $targetPerson->emailEmail->set('activityTestTargetPerson@dio.de');
    $sourcePerson->firstName->set('Target Person');
    $targetPerson->save();

    $activity = new ActivityBasic();
    $activity->setSourcePerson($sourcePerson);
    $activity->setTargets([$targetPerson]);
    $activity->activityDateTime->set('2020-03-04 05:06:07');
    $activity->activityTypeName->set('Bulk Email');
    $activity->subject->set('Test Activity');

    $activity->save();

    // FETCH COMPONENTS

    /** @var \Civi\Osdi\LocalObject\ActivityBasic $activityFromDatabase */
    $activityFromDatabase = ActivityBasic::fromId($activity->getId());

    self::assertNotEquals($sourcePerson, $activityFromDatabase->getSourcePerson());
    self::assertEquals($sourcePerson->getId(), $activityFromDatabase->getSourcePerson()->getId());
    self::assertNotEquals([$targetPerson], $activityFromDatabase->getTargetPeople());
    self::assertEquals($targetPerson->getId(), $activityFromDatabase->getTargetPeople()[0]->getId());
  }

  public function testCreate_TrySave() {
    self::markTestIncomplete('todo');
    // CREATE
    $tag = new TagBasic();
    $tag->name->set('Tagalina');

    $person = new PersonBasic();
    $person->emailEmail->set('tagteam@dio.de');

    $activity = new ActivityBasic();
    $activity->setTag($tag);
    $activity->setPerson($person);
    $result = $activity->trySave();

    self::assertTrue($result->isError());

    $person->save();
    $tag->save();
    $result = $activity->trySave();

    self::assertFalse($result->isError());
  }

  public function testCreate_Delete() {
    self::markTestIncomplete('todo');
    // CREATE
    $tag = new TagBasic();
    $tag->name->set('Tagalina');
    $tag->save();

    $person = new PersonBasic();
    $person->emailEmail->set('tagteam@dio.de');
    $person->save();

    $activity = new ActivityBasic();
    $activity->setTag($tag);
    $activity->setPerson($person);
    $activity->save();

    // DELETE
    $activityId = $activity->getId();
    $activity->delete();
    $this->expectException(\Civi\Osdi\Exception\InvalidArgumentException::class);
    ActivityBasic::fromId($activityId);
  }

  public function testisAltered() {
    self::markTestIncomplete('todo');
    $tag = new TagBasic();
    $tag->name->set('Tagalina');
    $tag->save();

    $person = new PersonBasic();
    $person->emailEmail->set('tagteam@dio.de');
    $person->save();

    $activity = new ActivityBasic();
    self::assertFalse($activity->isAltered());

    $activity->setTag($tag);
    self::assertTrue($activity->isAltered());

    $activity->setPerson($person);
    self::assertTrue($activity->isAltered());

    $activity->save();
    self::assertFalse($activity->isAltered());
  }

}
