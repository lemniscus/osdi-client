<?php

namespace Civi\Osdi\ActionNetwork\CrmEventResponder;

use Civi;
use Civi\Osdi\ActionNetwork\Object\Person as RemotePerson;
use Civi\Osdi\LocalObject\PersonBasic as LocalPerson;
use Civi\OsdiClient;
use OsdiClient\ActionNetwork\TestUtils;

/**
 * @group headless
 */
class PersonBasicTest extends \PHPUnit\Framework\TestCase implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\HookInterface {

  private static array $objectsToDelete = [];

  private static array $tableMaxIds = [];

  private static \Civi\Osdi\ActionNetwork\RemoteSystem $system;

  private static bool $mergeEmailFromDupe = FALSE;

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    $cleanupTables = [
      'civicrm_cache',
      'civicrm_contact',
      'civicrm_osdi_deletion',
      'civicrm_osdi_person_sync_state',
      'civicrm_osdi_sync_profile',
      'civicrm_queue',
      'civicrm_queue_item',
      'civicrm_tag',
    ];
    foreach ($cleanupTables as $cleanupTable) {
      $max = \CRM_Core_DAO::singleValueQuery("SELECT MAX(id) FROM $cleanupTable");
      self::$tableMaxIds[$cleanupTable] = $max;
    };
  }

  protected function setUp(): void {
    self::$system = TestUtils::createRemoteSystem();
    self::$mergeEmailFromDupe = FALSE;
    Civi\Osdi\Queue::getQueue(TRUE);
    parent::setUp();
  }

  protected function tearDown(): void {
    foreach (self::$tableMaxIds as $table => $maxId) {
      $where = $maxId ? "WHERE id > $maxId" : "";
      \CRM_Core_DAO::singleValueQuery("DELETE FROM $table $where");
    }

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

  private function runQueueViaCiviApi3(): void {
    $syncProfileId = OsdiClient::container()->getSyncProfileId();

    $queueJobResult = civicrm_api3('Job', 'osdiclientprocessqueue',
      ['debug' => 1, 'sync_profile_id' => $syncProfileId]);

    self::assertEquals(0, $queueJobResult['is_error']);
  }

  private function makeLocalPerson(string $index): LocalPerson {
    $localPerson = new LocalPerson();
    $localPerson->emailEmail->set("contactEventResponderTest$index@test.net");
    $localPerson->firstName->set("Test Contact Event Responder $index");
    $localPerson->save();
    return $localPerson;
  }

  private function makeRemotePerson(string $index): RemotePerson {
    $remotePerson = new RemotePerson(self::$system);
    $remotePerson->emailAddress->set("contactEventResponderTest$index@test.net");
    $remotePerson->givenName->set("Test Contact Event Responder $index");
    $remotePerson->save();
    return $remotePerson;
  }

  /**
   * @param string $index
   *
   * @return array{0: \Civi\Osdi\LocalObject\PersonBasic, 1: \Civi\Osdi\ActionNetwork\Object\Person}
   * @throws \Civi\Osdi\Exception\InvalidOperationException
   */
  private function makeSamePersonOnBothSides(string $index): array {
    $localPerson = $this->makeLocalPerson($index);
    $remotePerson = $this->makeRemotePerson($index);
    return [$localPerson, $remotePerson];
  }

  public function testCreationAndUpdateAndDeletionByOsdiClientIsIgnored() {
    $localPerson = $this->makeLocalPerson(1);

    $localPerson->addressStreetAddress->set(__FUNCTION__);
    $localPerson->save();
    $localPerson->delete();

    $queue = Civi\Osdi\Queue::getQueue();

    self::assertEquals(0, $queue->getStatistic('total'));
  }

  public function testCreationAndUpdateAreAddedToQueue() {
    self::markTestIncomplete('todo');
    $localPerson = $this->makeLocalPerson(1);

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
    [$mainLocalPerson, $remotePerson1] = $this->makeSamePersonOnBothSides(1);
    $dupeLocalPerson = $this->makeLocalPerson(microtime());

    $toRemoveId = $dupeLocalPerson->getId();
    $toKeepId = $mainLocalPerson->getId();
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

    self::assertEquals(0, $queue->numberOfItems(),
      /*print_r($queue->claimItem()->data, TRUE)*/);
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

    $this->runQueueViaCiviApi3();

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

    $this->runQueueViaCiviApi3();

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

    $this->runQueueViaCiviApi3();

    $expected = ['1b' => '1b', '2b' => '2b'];
    $actual = $this->listTaggings($remotePeople);

    self::assertEquals($expected, $actual);
  }






  public function testHandleErrorDuringQueueRun() {
    self::markTestIncomplete('TODO');
  }

}
