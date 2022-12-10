<?php

namespace Civi\Osdi\ActionNetwork\CrmEventResponder;

use Civi\Api4\EntityTag;
use Civi\Osdi\Factory;
use Civi\Core\DAO\Event\PreDelete;
use Civi\Core\DAO\Event\PreUpdate;
use Civi\Osdi\LocalRemotePair;
use CRM_OSDI_ExtensionUtil as E;

class TaggingBasic {

  public function daoPreUpdate(PreUpdate $event) {
    /** @var \CRM_Core_DAO_EntityTag $dao */
    $dao = $event->object;

    if ($this->isCallComingFromInsideTheHouse('save', ['id' => $dao->id])) {
      return;
    }

    if ('civicrm_contact' !== $dao->entity_table) {
      return;
    }

    $preUpdateDaoArray = $this->getEntityTagArrayById($dao->id);
    $preUpdateLocalObjectArray =
      $this->mapFieldNamesFromDaoToLocalObject($preUpdateDaoArray);

    $task = new \CRM_Queue_Task(
      [static::class, 'syncDeletionFromQueue'],
      ['serializedTagging' => $preUpdateLocalObjectArray],
      E::ts('Sync update of EntityTag id %1: delete old version',
        [1 => $preUpdateLocalObjectArray['id']])
    );

    $queue = \Civi\Osdi\Queue::getQueue();
    $queue->createItem($task);
  }

  public function daoPreDelete(PreDelete $event) {
    /** @var \CRM_Core_DAO_EntityTag $dao */
    $dao = $event->object;

    if ($this->isCallComingFromInsideTheHouse('delete', ['id' => $dao->id])) {
      return;
    }

    if ('civicrm_contact' !== $dao->entity_table) {
      return;
    }

    $taggingAsArray = $this->makeLocalObjectArrayFromDao($dao);

    $task = new \CRM_Queue_Task(
      [static::class, 'syncDeletionFromQueue'],
      ['serializedTagging' => $taggingAsArray],
      E::ts('Sync deletion of EntityTag with tag id %1, contact id %2',
        [1 => $taggingAsArray['tagId'], 2 => $taggingAsArray['contactId']])
    );

    $queue = \Civi\Osdi\Queue::getQueue();
    $queue->createItem($task);
  }

  public function postCommit($op, $objectName, $objectId, &$objectRef) {
    if ($op === 'create') {
      $this->respondToPostCreation($objectId, $objectRef);
    }
  }

  protected function respondToPostCreation(int $tagId, array $entityTagHookData) {
    if ($entityTagHookData[1] !== 'civicrm_contact') {
      return;
    }

    $queue = \Civi\Osdi\Queue::getQueue();

    foreach ($entityTagHookData[0] as $contactId) {
      $taggingAsArray = [
        'entity_table' => 'civicrm_contact',
        'entity_id' => $contactId,
        'tag_id' => $tagId,
      ];

      if ($this->isCallComingFromInsideTheHouse('save', $taggingAsArray)) {
        return;
      }

      $task = new \CRM_Queue_Task(
        [static::class, 'syncCreationFromQueue'],
        ['serializedTagging' => $taggingAsArray],
        E::ts('Sync creation of EntityTag with tag id %1, contact id %2',
          [1 => $tagId, 2 => $contactId])
      );

      $queue->createItem($task);
    }
  }

  protected function isCallComingFromInsideTheHouse(string $op, array $taggingFromHook): bool {
    foreach (\Civi::$statics['osdiClient.inProgress'][$op] ?? [] as $objectFromUs) {
      if ('EntityTag' !== $objectFromUs::getCiviEntityName()) {
        continue;
      }

      if (empty($objectFromUs->getId()) || empty($taggingFromHook['id'])) {
        $tagIdFromHook = $taggingFromHook['tag_id'] ?? NULL;
        $contactIdFromHook = $taggingFromHook['entity_id'] ?? NULL;
        $tagIdFromUs = $objectFromUs->getTag()->getId();
        $contactIdFromUs = $objectFromUs->getPerson()->getId();
        return $tagIdFromHook && $contactIdFromHook
          && $tagIdFromUs === $tagIdFromHook
          && $contactIdFromUs === $contactIdFromHook;
      }

      if ($objectFromUs->getId() == $taggingFromHook['id']) {
        return TRUE;
      }
    }

    return FALSE;
  }

  public static function syncCreationFromQueue(
    \CRM_Queue_TaskContext $context,
    array $serializedTagging
  ) {
    $localTagging = Factory::make('LocalObject', 'Tagging');
    $localTagging->loadFromArray($serializedTagging);

    $remoteSystem = Factory::singleton('RemoteSystem', 'ActionNetwork');
    /** @var \Civi\Osdi\ActionNetwork\SingleSyncer\TaggingBasic $syncer */
    $syncer = Factory::singleton('SingleSyncer', 'Tagging', $remoteSystem);

    $pair = $syncer->toLocalRemotePair($localTagging);
    $pair->setOrigin(LocalRemotePair::ORIGIN_LOCAL);

    $result = $syncer->oneWayMapAndWrite($pair);
    return !($result->isError());
  }

  public static function syncDeletionFromQueue(
    \CRM_Queue_TaskContext $context,
    array $serializedTagging
  ) {
    $localTagging = Factory::make('LocalObject', 'Tagging');
    $localTagging->loadFromArray($serializedTagging);

    $remoteSystem = Factory::singleton('RemoteSystem', 'ActionNetwork');
    /** @var \Civi\Osdi\ActionNetwork\SingleSyncer\TaggingBasic $syncer */
    $syncer = Factory::singleton('SingleSyncer', 'Tagging', $remoteSystem);

    $pair = $syncer->toLocalRemotePair($localTagging);
    $pair->setOrigin(LocalRemotePair::ORIGIN_LOCAL);

    $result = $syncer->syncDeletion($pair);
    return !($result->isError());
  }

  protected function makeLocalObjectArrayFromDao(\CRM_Core_DAO_EntityTag $dao): array {
    $daoArray = $dao->toArray();

    if (empty($daoArray['tag_id']) || empty($daoArray['entity_id'])) {
      if (!empty($daoArray['id'])) {
        $daoArray = $this->getEntityTagArrayById($daoArray['id']);
      }
    }

    return $this->mapFieldNamesFromDaoToLocalObject($daoArray);
  }

  protected function getEntityTagArrayById($id): array {
    return EntityTag::get(FALSE)
      ->addWhere('id', '=', $id)->execute()->single();
  }

  protected function mapFieldNamesFromDaoToLocalObject(array $a): array {
    return ['id' => $a['id'], 'tagId' => $a['tag_id'], 'contactId' => $a['entity_id']];
  }

}
