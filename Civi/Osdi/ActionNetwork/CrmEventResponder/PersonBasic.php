<?php

namespace Civi\Osdi\ActionNetwork\CrmEventResponder;

use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\OsdiPersonSyncState;
use Civi\Core\DAO\Event\PreDelete;
use Civi\Core\DAO\Event\PreUpdate;
use Civi\Osdi\BatchSyncerInterface;
use Civi\Osdi\Factory;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\SingleSyncerInterface;
use CRM_OSDI_ExtensionUtil as E;

class PersonBasic {

  public function alterLocationMergeData(&$blocksDAO, $mainId, $otherId, $migrationInfo) {
    //if (empty($blocksDAO['email'])) {
    //  /** @var \Civi\Osdi\LocalObject\PersonBasic $toBeDeletedPerson */
    //  // "other" person's email is being abandoned
    //  $toBeDeletedPerson =
    //    Factory::make('LocalObject', 'Person', $otherId)->loadOnce();
    //
    //  $this->queueCreateDeletionRecord($toBeDeletedPerson->getAll());
    //}

    //$emailsBeingDeleted = $blocksDAO['email']['delete'] ?? [];
    //$emailIdsBeingDeleted = [];
    //foreach ($emailsBeingDeleted as $emailDAO) {
    //  $emailIdsBeingDeleted[] = $emailDAO->id;
    //}
    //if (!$emailIdsBeingDeleted) {
    //  return;
    //}
    //$primaryEmailBeingDeleted = Email::get(FALSE)
    //  ->addWhere('id', 'IN', $emailIdsBeingDeleted)
    //  ->addWhere('is_primary', '=', TRUE)
    //  ->execute()->first();
    //if (empty($primaryEmailBeingDeleted)) {
    //  return;
    //}
    //
    //$emailsBeingUpdated = $blocksDAO['email']['update'] ?? [];
    //$primaryEmailIdsBeingUpdated = [];
    //foreach ($emailsBeingUpdated as $emailDAO) {
    //  if ($emailDAO->is_primary) {
    //    $primaryEmailIdsBeingUpdated[] = $emailDAO->id;
    //  }
    //}
    //if ($primaryEmailIdsBeingUpdated) {
    //  $primaryEmailBeingMoved = Email::get(FALSE)
    //    ->addWhere('id', 'IN', $primaryEmailIdsBeingUpdated)
    //    //->addWhere('is_primary', '=', TRUE)
    //    ->execute()->single();
    //}
    //
    //if (!empty($primaryEmailBeingMoved)) {
    //  if ($primaryEmailBeingDeleted['email'] === $primaryEmailBeingMoved['email']) {
    //    return;
    //  }
    //}
    //
    //$localPersonArray = [
    //  'id' => $primaryEmailBeingDeleted['contact_id'],
    //  'emailEmail' => $primaryEmailBeingDeleted['email'],
    //];
    //
    //$this->queueCreateDeletionRecord($localPersonArray);
  }

  public function daoPreDelete(PreDelete $event) {
    /** @var \CRM_Contact_DAO_Contact $dao */
    $dao = $event->object;

    if ($this->responseIsSuppressed('delete', ['id' => $dao->id])) {
      return;
    }

    $localPersonArray = $this->makeLocalObjectArrayFromDao($dao);

    $task = new \CRM_Queue_Task(
      [static::class, 'syncDeletionFromQueue'],
      ['serializedPerson' => $localPersonArray],
      E::ts('Sync hard deletion of Contact id %1',
        [1 => $localPersonArray['id']])
    );

    $queue = \Civi\Osdi\Queue::getQueue();
    $queue->createItem($task, ['weight' => -10]);
  }

  public function daoPreUpdate(PreUpdate $event) {
    /** @var \CRM_Contact_DAO_Contact $dao */
    $dao = $event->object;

    $this->respondToDaoPreUpdateSoftDelete($dao);
  }

  public function merge(int $idBeingKept, int $idBeingDeleted) {
    //OsdiPersonSyncState::delete(FALSE)
    //  ->addWhere('contact_id', 'IN', [$idBeingKept, $idBeingDeleted])
    //  ->execute();

    \Civi::$statics['osdiClient.inProgress']['delete'][] =
      Factory::make('LocalObject', 'Person', $idBeingDeleted);
    \Civi::$statics['osdiClient.inProgress']['delete'][] =
      Factory::make('LocalObject', 'Person', $idBeingKept);

    $queue = \Civi\Osdi\Queue::getQueue();

    //$localPersonArray =
    //  Factory::make('LocalObject', 'Person', $idBeingDeleted)
    //    ->loadOnce()
    //    ->getAll();
    //$task = new \CRM_Queue_Task(
    //  [static::class, 'syncDeletionFromQueue'],
    //  ['serializedPerson' => $localPersonArray],
    //  E::ts('Sync merge deletion of Contact id %1',
    //    [1 => $localPersonArray['id']])
    //);
    //$queue->createItem($task, ['weight' => -15]);
    //
    //$localPersonArray =
    //  Factory::make('LocalObject', 'Person', $idBeingKept)
    //    ->loadOnce()
    //    ->getAll();
    //$task = new \CRM_Queue_Task(
    //  [static::class, 'syncDeletionFromQueue'],
    //  ['serializedPerson' => $localPersonArray],
    //  E::ts('Sync merge deletion of Contact id %1',
    //    [1 => $localPersonArray['id']])
    //);
    //$queue->createItem($task, ['weight' => -15]);

    $task = new \CRM_Queue_Task(
      [static::class, 'syncCreationFromQueue'],
      ['serializedPerson' => ['id' => $idBeingKept]],
      E::ts('Sync merge of Contact id %1 into id %2',
        [1 => $idBeingDeleted, 2 => $idBeingKept])
    );
    $queue->createItem($task, ['weight' => -10]);

    $task = new \CRM_Queue_Task(
      [static::class, 'syncLocalPersonTaggings'],
      ['contactId' => $idBeingKept],
      E::ts('Sync all taggings of Contact id %1',
        [1 => $idBeingKept])
    );
    $queue->createItem($task, ['weight' => -5]);
  }

  public static function post(string $op, string $objectName, int $objectId, &$objectRef) {
    if ($op !== 'merge') {
      return;
    }

    //$apiGetResult = OsdiPersonSyncState::get(FALSE)
    //  ->addWhere('contact_id', '=', $objectId)
    //  ->addOrderBy('sync_profile_id')
    //  ->addOrderBy('remote_person_id IS NULL', 'DESC')
    //  ->addOrderBy('sync_time', 'ASC')
    //  ->execute();

    $query = 'SELECT id, sync_profile_id, sync_status, (remote_person_id IS NOT NULL) AS has_remote
      FROM ' . \CRM_OSDI_DAO_PersonSyncState::getTableName() . "
      WHERE contact_id = $objectId
      ORDER BY has_remote, sync_time";
    $contactPss = \CRM_Core_DAO::executeQuery($query)->fetchAll();

    // keep only the contact's most recent PersonSyncState, per sync profile id
    foreach ($contactPss as $pss) {
      if (isset($olderPss) && $pss['sync_profile_id'] === $olderPss['sync_profile_id']) {
        OsdiPersonSyncState::delete(FALSE)
          ->addWhere('id', '=', $olderPss['id'])
          ->execute();
      }
      $olderPss = $pss;
    }

    // keep only the contact's most recent PersonSyncState, per sync profile id
    //foreach ($apiGetResult as $pss) {
    //  if (isset($olderPss) && $pss['sync_profile_id'] === $olderPss['sync_profile_id']) {
    //    OsdiPersonSyncState::delete(FALSE)
    //      ->addWhere('id', '=', $olderPss['id'])
    //      ->execute();
    //  }
    //  $olderPss = $pss;
    //}
  }

  public static function syncCreationFromQueue(
    \CRM_Queue_TaskContext $context,
    array $serializedPerson
  ) {
    $localPerson = Factory::make('LocalObject', 'Person');
    $localPerson->loadFromArray($serializedPerson);
    if (count($serializedPerson) === 1) {
      $localPerson->load();
    }

    $syncer = self::getPersonSingleSyncer();
    $pair = $syncer->matchAndSyncIfEligible($localPerson);
    return !($pair->isError());
  }

  public static function createDeletionRecordFromQueue(
    \CRM_Queue_TaskContext $context,
    array $serializedPerson
  ) {
    $localPerson = Factory::make('LocalObject', 'Person');
    $localPerson->loadFromArray($serializedPerson);

    $syncer = self::getPersonSingleSyncer();

    $pair = $syncer->toLocalRemotePair($localPerson);
    $pair->setOrigin(LocalRemotePair::ORIGIN_LOCAL);

    $result = $syncer->fetchOldOrFindNewMatch($pair);

    if ($result->hasMatch()) {
      $deletionRecord = [
        'sync_profile_id' => $syncer->getSyncProfile()['id'] ?? NULL,
        'remote_object_id' => $pair->getTargetObject()->getId(),
      ];
      \Civi\Api4\OsdiDeletion::save(FALSE)
        ->setMatch(['sync_profile_id', 'remote_object_id'])
        ->setRecords([$deletionRecord])
        ->execute();
    }
  }

  public static function syncDeletionFromQueue(
    \CRM_Queue_TaskContext $context,
    array $serializedPerson
  ) {
    $localPerson = Factory::make('LocalObject', 'Person');
    $localPerson->loadFromArray($serializedPerson);

    $syncer = self::getPersonSingleSyncer();

    $pair = $syncer->toLocalRemotePair($localPerson);
    $pair->setOrigin(LocalRemotePair::ORIGIN_LOCAL);

    $result = $syncer->syncDeletion($pair);
    return !($result->isError());
  }

  public static function syncLocalPersonTaggings(
    \CRM_Queue_TaskContext $context,
    int $contactId
  ) {
    $localPerson = Factory::make('LocalObject', 'Person', $contactId);
    $syncer = self::getTaggingBatchSyncer();
    $syncer->syncTaggingsFromLocalPerson($localPerson);
  }

  /**
   * @param int|string $id
   */
  protected function getContactAsArray($id) {
    return Contact::get(FALSE)
      ->addWhere('id', '=', $id)->execute()->single();
  }

  protected function responseIsSuppressed(string $op, array $entityArrayFromHook): bool {
    foreach (\Civi::$statics['osdiClient.inProgress'][$op] ?? [] as $objectFromUs) {
      if ('Contact' !== $objectFromUs::getCiviEntityName()) {
        continue;
      }

      if (empty($objectFromUs->getId()) || empty($entityArrayFromHook['id'])) {
        // The contact record is newly-created. We don't have a unique identifier
        // to match on. Using first & last name is far from perfect, but as of
        // this writing we anticipate there will only be one Contact in the
        // 'inProgress' list at any given time, and only one contact coming in
        // through the hook, so this should be fine
        $firstNameFromUs = (string) $objectFromUs->firstName->get();
        $firstNameFromHook = (string) $entityArrayFromHook['first_name'] ?? '';
        $lastNameFromUs = (string) $objectFromUs->lastName->get();
        $lastNameFromHook = (string) $entityArrayFromHook['last_name'] ?? '';
        return $firstNameFromUs === $firstNameFromHook
          && $lastNameFromUs === $lastNameFromHook;
      }

      if ($objectFromUs->getId() == $entityArrayFromHook['id']) {
        return TRUE;
      }
    }

    return FALSE;
  }

  protected function makeLocalObjectArrayFromDao(\CRM_Contact_DAO_Contact $dao): array {
    $localPerson = Factory::make('LocalObject', 'Person', $dao->id);
    return $localPerson->loadOnce()->getAll();
  }

  protected function respondToDaoPreUpdateSoftDelete(\CRM_Contact_DAO_Contact $dao) {
    if ($this->responseIsSuppressed('delete', ['id' => $dao->id])) {
      return;
    }

    if (!$dao->is_deleted) {
      return;
    }

    $preUpdateDaoArray = $this->getContactAsArray($dao->id);

    if ($preUpdateDaoArray['is_deleted']) {
      return;
    }

    // In this update, contact is being soft-deleted

    $localPersonArray = $this->makeLocalObjectArrayFromDao($dao);

    $task = new \CRM_Queue_Task(
      [static::class, 'syncDeletionFromQueue'],
      ['serializedPerson' => $localPersonArray],
      E::ts('Sync soft deletion of Contact id %1',
        [1 => $localPersonArray['id']])
    );

    $queue = \Civi\Osdi\Queue::getQueue();
    $queue->createItem($task, ['weight' => -10]);
  }

  private function queueCreateDeletionRecord(array $localPersonArray): void {
    $task = new \CRM_Queue_Task(
      [static::class, 'createDeletionRecordFromQueue'],
      ['serializedPerson' => $localPersonArray],
      E::ts('Create deletion record for Contact id %1, %2',
        [1 => $localPersonArray['id'], 2 => $localPersonArray['emailEmail']])
    );

    $queue = \Civi\Osdi\Queue::getQueue();
    $queue->createItem($task);
  }

  private static function getPersonSingleSyncer(): SingleSyncerInterface {
    $remoteSystem = Factory::singleton('RemoteSystem', 'ActionNetwork');
    $syncer = Factory::singleton('SingleSyncer', 'Person', $remoteSystem);
    return $syncer;
  }

  private static function getTaggingBatchSyncer(): BatchSyncerInterface {
    $remoteSystem = Factory::singleton('RemoteSystem', 'ActionNetwork');
    $singleSyncer = Factory::singleton('SingleSyncer', 'Tagging', $remoteSystem);
    $batchSyncer = Factory::singleton('BatchSyncer', 'Tagging', $singleSyncer);
    return $batchSyncer;
  }

}
