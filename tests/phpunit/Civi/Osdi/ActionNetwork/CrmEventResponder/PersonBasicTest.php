<?php

namespace Civi\Osdi\ActionNetwork\CrmEventResponder;

use Civi;
use CRM_OSDI_ActionNetwork_TestUtils;
use PHPUnit;

/**
 * @group headless
 */
class PersonBasicTest extends PHPUnit\Framework\TestCase implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\HookInterface {

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
    \CRM_Core_DAO::singleValueQuery('DELETE FROM civicrm_osdi_deletion');

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

  private function makeLocalPerson(string $index): Civi\Osdi\LocalObject\PersonBasic {
    $localPerson = new \Civi\Osdi\LocalObject\PersonBasic();
    $localPerson->emailEmail->set("contactEventResponderTest$index@test.net");
    $localPerson->firstName->set("Test Contact Event Responder $index");
    $localPerson->save();
    return $localPerson;
  }

  private function makeRemotePerson(string $index): Civi\Osdi\ActionNetwork\Object\Person {
    $remotePerson = new Civi\Osdi\ActionNetwork\Object\Person(self::$system);
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
    [$localPerson1, $remotePerson1] = $this->makeSamePersonOnBothSides(1);
    [$localPerson2, $remotePerson2] = $this->makeSamePersonOnBothSides(2);

    array_push(self::$objectsToDelete, $localPerson1, $localPerson2);

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
      "Sync soft deletion of Contact id $toRemoveId",
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

  public static function hook_civicrm_merge_forTest($type, &$data, $mainId = NULL, $otherId = NULL, $tables = NULL) {
    if ($type === 'batch') {
      $data['fields_in_conflict']['move_location_email_0'] = TRUE;
      // '2' means 'overwrite': https://github.com/civicrm/civicrm-core/blob/master/CRM/Dedupe/MergeHandler.php#L186
      $data['migration_info']['location_blocks']['email'][0]['operation'] = 2;
      $data['migration_info']['location_blocks']['email'][0]['mainContactBlockId'] =
        $data['migration_info']['main_details']['location_blocks']['email'][0]['id'];
    }
  }

  public function testUpdateDueToContactMerge_OverwriteEmail() {
    [$localPerson1, $remotePerson1] = $this->makeSamePersonOnBothSides(1);
    [$localPerson2, $remotePerson2] = $this->makeSamePersonOnBothSides(2);

    array_push(self::$objectsToDelete, $localPerson1, $localPerson2);

    self::assertNotEmpty($remotePerson1->givenName->get());
    self::assertNotEmpty($remotePerson2->givenName->get());

    // Person 2's Civi record will be kept in the merge, including its name,
    // and Person 1 will be deleted,
    // but Person 1's email will be transferred to Person 2.
    // We control this via hook_civicrm_merge.

    Civi::dispatcher()->addListener(
      '&hook_civicrm_merge', [__CLASS__, 'hook_civicrm_merge_forTest']);

    $toKeepId = $localPerson2->getId();
    $toKeepName = $localPerson2->firstName->get();
    $toKeepEmail = $localPerson1->emailEmail->get();

    $toRemoveId = $localPerson1->getId();
    $toRemoveName = $localPerson1->firstName->get();
    $toRemoveEmail = $localPerson2->emailEmail->get();

    civicrm_api3('Contact', 'merge', [
      'to_remove_id' => $toRemoveId,
      'to_keep_id' => $toKeepId,
      'mode' => 'aggressive',
    ]);

    $queueJobResult = civicrm_api3('Job', 'osdiclientprocessqueue',
      ['debug' => 1, 'api_token' => ACTION_NETWORK_TEST_API_TOKEN]);

    self::assertEquals(0, $queueJobResult['is_error']);

    $mergedLocalPerson = new Civi\Osdi\LocalObject\PersonBasic($toKeepId);
    $mergedLocalPerson->load();

    self::assertEquals(
      $toKeepEmail,
      $mergedLocalPerson->emailEmail->get());

    $reFetchedRemotePerson1 = Civi\Osdi\ActionNetwork\Object\Person::loadFromId(
      $remotePerson1->getId(), self::$system);

    $reFetchedRemotePerson2 = Civi\Osdi\ActionNetwork\Object\Person::loadFromId(
      $remotePerson2->getId(), self::$system);

    // The twin of the merged record contains the merged name. The twin of the
    // removed record ALSO still has that name (it is unchanged)

    self::assertEquals($remotePerson1->emailAddress->get(),
      $reFetchedRemotePerson1->emailAddress->get());

    self::assertEquals($toKeepName,
      $reFetchedRemotePerson1->givenName->get());

    self::assertEquals($remotePerson2->emailAddress->get(),
      $reFetchedRemotePerson2->emailAddress->get());

    self::assertEquals($toKeepName,
      $reFetchedRemotePerson2->givenName->get());

    // The PersonSyncState for the kept Civi contact indicates an error.
    // An OsdiDeletion exists for the deleted person, even though their AN record is intact.

    $deletionRecord = Civi\Api4\OsdiDeletion::get(FALSE)
      ->addWhere('remote_object_id', '=', $remotePerson1->getId())
      ->execute()->single();

    $deletedPersonSyncState = Civi\Osdi\PersonSyncState::getForRemotePerson(
      $remotePerson1, NULL);
    // uh oh -- no osdideletion was created because the syncer couldn't find a match without an email

    $keptPersonSyncState = Civi\Osdi\PersonSyncState::getForRemotePerson(
      $remotePerson2, NULL);

    self::assertEmpty($deletedPersonSyncState->getId());
    self::assertStringContainsString('error', $keptPersonSyncState->getSyncStatus());
    self::assertArrayHasKey('error', $deletionRecord);
  }

  public function testHandleErrorDuringQueueRun() {
    self::markTestIncomplete('TODO');
  }

}
