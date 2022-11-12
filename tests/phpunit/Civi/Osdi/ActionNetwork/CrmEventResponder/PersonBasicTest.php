<?php

namespace Civi\Osdi\ActionNetwork\CrmEventResponder;

use Civi;
use CRM_OSDI_ActionNetwork_TestUtils;
use PHPUnit;

/**
 * @group headless
 */
class PersonBasicTest extends PHPUnit\Framework\TestCase implements
    \Civi\Test\HeadlessInterface {

  private static array $objectsToDelete = [];

  private static \Civi\Osdi\ActionNetwork\RemoteSystem $system;

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();;
  }

  protected function setUp(): void {
    self::$system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
    Civi\Osdi\Queue::getQueue(TRUE);
    parent::setUp();
  }

  protected function tearDown(): void {
    foreach (self::$objectsToDelete as $object) {
      try {
        if ('Contact' === $object::getCiviEntityName()) {
          \CRM_Core_DAO::singleValueQuery('DELETE FROM civicrm_contact '
            . 'WHERE id = ' . $object->getId());
          continue;
        }
        $object->delete();
      }
      catch (\Throwable $e) {
        $class = get_class($object);
        print "Could not delete $class\n";
      }
    }

    Civi\Osdi\Queue::getQueue(TRUE);

    parent::tearDown();
  }

  public static function tearDownAfterClass(): void {
    parent::tearDownAfterClass();
  }

  /**
   * @param string $index
   *
   * @return array{0: \Civi\Osdi\LocalObject\PersonBasic, 1: \Civi\Osdi\ActionNetwork\Object\Person}
   * @throws \Civi\Osdi\Exception\InvalidOperationException
   */
  private function makeSamePersonOnBothSides(string $index): array {
    $email = "contactEventResponderTest$index@test.net";
    $givenName = "Test Contact Event Responder $index";

    $remotePerson = new Civi\Osdi\ActionNetwork\Object\Person(self::$system);
    $remotePerson->emailAddress->set($email);
    $remotePerson->givenName->set($givenName);
    $remotePerson->save();

    $localPerson = new \Civi\Osdi\LocalObject\PersonBasic();
    $localPerson->emailEmail->set($email);
    $localPerson->firstName->set($givenName);
    $localPerson->save();
    return [$localPerson, $remotePerson];
  }

  public function testCreationAndUpdateAndDeletionByOsdiClientIsIgnored() {
    [$localPerson, $remotePerson] = $this->makeSamePersonOnBothSides(1);

    $localPerson->addressStreetAddress->set(__FUNCTION__);
    $localPerson->save();
    $localPerson->delete();

    $queue = Civi\Osdi\Queue::getQueue();

    self::assertEquals(0, $queue->getStatistic('total'));
  }

  public function testCreationAndUpdateAreAddedToQueue() {
    self::markTestIncomplete('todo');
    [$localTags, $remoteTags] = $this->makeSameTagsOnBothSides();
    [$localPerson, $remotePerson1] = $this->makeSamePersonOnBothSides(1);

    array_push(self::$objectsToDelete,
      $localTags['a'], $localTags['b'], $localPerson);

    $queue = Civi\Osdi\Queue::getQueue(TRUE);

    $contactId = $localPerson->getId();
    $tagAId = $localTags['a']->getId();
    $entityTagId = Civi\Api4\EntityTag::create(FALSE)
      ->addValue('tag_id', $tagAId)
      ->addValue('entity_table', 'civicrm_contact')
      ->addValue('entity_id', $contactId)
      ->execute()->single()['id'];

    $item = $queue->claimItem();
    self::assertNotNull($item);

    /** @var \CRM_Queue_Task $task */
    $task = $item->data;

    self::assertEquals(\CRM_Queue_Task::class, get_class($task));
    self::assertEquals("Sync creation of EntityTag with tag id $tagAId, contact id $contactId", $task->title);

    $queue->deleteItem($item);

    $tagBId = $localTags['b']->getId();
    Civi\Api4\EntityTag::update(FALSE)
      ->addWhere('id', '=', $entityTagId)
      ->addValue('entity_id', $contactId)
      ->addValue('entity_table', 'civicrm_contact')
      ->addValue('tag_id', $tagBId)
      ->execute();

    $item = $queue->claimItem();
    self::assertNotNull($item);

    /** @var \CRM_Queue_Task $task */
    $task = $item->data;

    self::assertEquals(\CRM_Queue_Task::class, get_class($task));
    self::assertEquals("Sync update of EntityTag id $entityTagId: delete old version", $task->title);

    $queue->deleteItem($item);
    $item = $queue->claimItem();
    self::assertNotNull($item);

    /** @var \CRM_Queue_Task $task */
    $task = $item->data;

    self::assertEquals(\CRM_Queue_Task::class, get_class($task));
    self::assertEquals("Sync creation of EntityTag with tag id $tagBId, contact id $contactId", $task->title);
  }

  public function testSoftDeletionIsAddedToQueue() {
    [$localPerson1, $remotePerson1] = $this->makeSamePersonOnBothSides(1);

    $queue = Civi\Osdi\Queue::getQueue(TRUE);

    self::assertEquals(0, $queue->getStatistic('ready'));

    Civi\Api4\Contact::delete(FALSE)
      ->addWhere('id', '=', $localPerson1->getId())
      ->setUseTrash(TRUE)
      ->execute();

    /** @var \CRM_Queue_Task $task */
    self::assertGreaterThan(0, $queue->getStatistic('ready'));
    $task = $queue->claimItem()->data;

    self::assertEquals(\CRM_Queue_Task::class, get_class($task));
    self::assertEquals('Sync soft deletion of Contact id ' . $localPerson1->getId(),
      $task->title);
  }

  public function testHardDeletionIsAddedToQueue() {
    [$localPerson1, $remotePerson1] = $this->makeSamePersonOnBothSides(1);

    $queue = Civi\Osdi\Queue::getQueue(TRUE);

    self::assertEquals(0, $queue->getStatistic('ready'));

    Civi\Api4\Contact::delete(FALSE)
      ->addWhere('id', '=', $localPerson1->getId())
      ->setUseTrash(FALSE)
      ->execute();

    /** @var \CRM_Queue_Task $task */
    self::assertGreaterThan(0, $queue->getStatistic('ready'));
    $task = $queue->claimItem()->data;

    self::assertEquals(\CRM_Queue_Task::class, get_class($task));
    self::assertEquals('Sync hard deletion of Contact id ' . $localPerson1->getId(),
      $task->title);
  }

  public function testMergesAreAddedToQueue() {
    self::markTestIncomplete('todo');
    [$localTags, $remoteTags] = $this->makeSameTagsOnBothSides();
    [$localPerson1, $remotePerson1] = $this->makeSamePersonOnBothSides(1);
    [$localPerson2, $remotePerson2] = $this->makeSamePersonOnBothSides(2);

    $localTagging1a = new Civi\Osdi\LocalObject\TaggingBasic();
    $localTagging1a->setPerson($localPerson1)->setTag($localTags['a'])->save();

    $localTagging2b = new Civi\Osdi\LocalObject\TaggingBasic();
    $localTagging2b->setPerson($localPerson2)->setTag($localTags['b'])->save();

    array_push(self::$objectsToDelete,
      $localTags['a'], $localTags['b'], $localPerson1, $localPerson2);

    $toRemoveId = $localPerson1->getId();
    $toKeepId = $localPerson2->getId();
    civicrm_api3('Contact', 'merge', [
      'to_remove_id' => $toRemoveId,
      'to_keep_id' => $toKeepId,
      'mode' => "aggressive",
    ]);

    $queue = Civi\Osdi\Queue::getQueue();
    $expectedTitles = [
      "Sync merge of Contact id $toRemoveId into id $toKeepId",
      "Sync all taggings of Contact id $toKeepId",
    ];

    foreach ($expectedTitles as $expectedTitle) {
      $item = $queue->claimItem();
      self::assertNotNull($item);

      $task = $item->data;

      self::assertEquals(\CRM_Queue_Task::class, get_class($task));
      self::assertEquals($expectedTitle, $task->title);

      $queue->deleteItem($item);
    }

    self::assertEquals(0, $queue->numberOfItems());
  }

  public function testDeleteMoreThanOne() {
    [$localPerson1, $remotePerson1] = $this->makeSamePersonOnBothSides(1);
    [$localPerson2, $remotePerson2] = $this->makeSamePersonOnBothSides(2);
    $localPeopleIds = [$localPerson1->getId(), $localPerson2->getId()];
    $remotePeople = [$remotePerson1, $remotePerson2];

    foreach ($remotePeople as $remotePerson) {
      $remotePerson->load();
      self::assertNotNull($remotePerson->getId());
      self::assertNotNull($remotePerson->givenName->get());
    }

    Civi\Api4\Contact::delete(FALSE)
      ->addWhere('id', 'IN', $localPeopleIds)
      ->setUseTrash(FALSE)
      ->execute();

    $result = civicrm_api3('Job', 'osdiclientprocessqueue',
      ['debug' => 1, 'api_token' => ACTION_NETWORK_TEST_API_TOKEN]);

    self::assertEquals(0, $result['is_error']);

    foreach ($remotePeople as $personThatShouldBeDeleted) {
      $e = NULL;
      $personThatShouldBeDeleted->load();
      self::assertNull($personThatShouldBeDeleted->givenName->get());
    }
  }

  public function testCreateMoreThanOne() {
    self::markTestIncomplete('todo');
    [$localTags, $remoteTags] = $this->makeSameTagsOnBothSides();
    [$localPerson1, $remotePerson1] = $this->makeSamePersonOnBothSides(1);
    [$localPerson2, $remotePerson2] = $this->makeSamePersonOnBothSides(2);
    $remotePeople = [$remotePerson1, $remotePerson2];
    $this->deleteAllTaggingsOnRemotePeople($remotePeople);

    array_push(self::$objectsToDelete,
      $localTags['a'], $localTags['b'], $localPerson1, $localPerson2);

    Civi\Api4\EntityTag::create(FALSE)
      ->addValue('tag_id', $localTags['a']->getId())
      ->addValue('entity_table', 'civicrm_contact')
      ->addValue('entity_id', $localPerson1->getId())
      ->execute();

    civicrm_api3('EntityTag', 'create', [
      'contact_id' => $localPerson2->getId(),
      'tag_id' => $localTags['a']->getId(),
    ]);

    $contactIds = [$localPerson1->getId(), $localPerson2->getId()];
    \CRM_Core_BAO_EntityTag::addEntitiesToTag(
      $contactIds,
      $localTags['b']->getId(),
      'civicrm_contact',
      FALSE);

    $result = civicrm_api3('Job', 'osdiclientprocessqueue',
      ['debug' => 1, 'api_token' => ACTION_NETWORK_TEST_API_TOKEN]);

    self::assertEquals(0, $result['is_error']);

    $expected = ['1a' => '1a', '1b' => '1b', '2a' => '2a', '2b' => '2b'];
    $actual = $this->listTaggings($remotePeople);

    self::assertEquals($expected, $actual);
  }

  public function testUpdateMoreThanOne() {
    self::markTestIncomplete('todo');
    [$localTags, $remoteTags] = $this->makeSameTagsOnBothSides();
    [$localPerson1, $remotePerson1] = $this->makeSamePersonOnBothSides(1);
    [$localPerson2, $remotePerson2] = $this->makeSamePersonOnBothSides(2);
    $remotePeople = [$remotePerson1, $remotePerson2];
    $this->deleteAllTaggingsOnRemotePeople($remotePeople);

    array_push(self::$objectsToDelete,
      $localTags['a'], $localTags['b'], $localPerson1, $localPerson2);

    $localTagging1a = new Civi\Osdi\LocalObject\TaggingBasic();
    $localTagging1a->setPerson($localPerson1)->setTag($localTags['a'])->save();

    $localTagging2a = new Civi\Osdi\LocalObject\TaggingBasic();
    $localTagging2a->setPerson($localPerson2)->setTag($localTags['a'])->save();

    $remoteTagging1a = new Civi\Osdi\ActionNetwork\Object\Tagging(self::$system);
    $remoteTagging1a->setPerson($remotePerson1)->setTag($remoteTags['a'])->save();

    $remoteTagging2a = new Civi\Osdi\ActionNetwork\Object\Tagging(self::$system);
    $remoteTagging2a->setPerson($remotePerson2)->setTag($remoteTags['a'])->save();

    //xdebug_break();
    foreach ([$localTagging1a, $localTagging2a] as $tagging) {
      Civi\Api4\EntityTag::update(FALSE)
        ->addWhere('id', 'IN', [$tagging->getId()])
        ->addValue('entity_table', 'civicrm_contact')
        ->addValue('entity_id', $tagging->getPerson()->getId())
        ->addValue('tag_id', $localTags['b']->getId())
        ->execute();
    }

    $result = civicrm_api3('Job', 'osdiclientprocessqueue',
      ['debug' => 1, 'api_token' => ACTION_NETWORK_TEST_API_TOKEN]);

    self::assertEquals(0, $result['is_error']);

    $expected = ['1b' => '1b', '2b' => '2b'];
    $actual = $this->listTaggings($remotePeople);

    self::assertEquals($expected, $actual);
  }

  public function testUpdateDueToContactMerge_DifferentEmails() {
    self::markTestIncomplete('todo');
    [$localTags, $remoteTags] = $this->makeSameTagsOnBothSides();
    [$localPerson1, $remotePerson1] = $this->makeSamePersonOnBothSides(1);
    [$localPerson2, $remotePerson2] = $this->makeSamePersonOnBothSides(2);

    $localTagging1a = new Civi\Osdi\LocalObject\TaggingBasic();
    $localTagging1a->setPerson($localPerson1)->setTag($localTags['a'])->save();

    $localTagging2b = new Civi\Osdi\LocalObject\TaggingBasic();
    $localTagging2b->setPerson($localPerson2)->setTag($localTags['b'])->save();

    array_push(self::$objectsToDelete,
      $localTags['a'], $localTags['b'], $localPerson1, $localPerson2);

    $toRemoveId = $localPerson1->getId();
    $toKeepId = $localPerson2->getId();
    civicrm_api3('Contact', 'merge', [
      'to_remove_id' => $toRemoveId,
      'to_keep_id' => $toKeepId,
      'mode' => "aggressive",
    ]);

    $result = civicrm_api3('Job', 'osdiclientprocessqueue',
      ['debug' => 1, 'api_token' => ACTION_NETWORK_TEST_API_TOKEN]);

    self::assertEquals(0, $result['is_error']);

    $expected = ['2a' => '2a', '2b' => '2b'];
    $actual = $this->listTaggings([$remotePerson2]);

    self::assertEquals($expected, $actual);
  }

  public function testHandleErrorDuringQueueRun() {
    self::markTestIncomplete('TODO');
  }

}
