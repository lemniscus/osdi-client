<?php

namespace Civi\Osdi\ActionNetwork\CrmEventResponder;

use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\OsdiFlag;
use Civi\Api4\OsdiPersonSyncState;
use Civi\Core\DAO\Event\PreDelete;
use Civi\Core\DAO\Event\PreUpdate;
use Civi\Osdi\BatchSyncerInterface;
use Civi\Osdi\Container;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\SingleSyncerInterface;
use CRM_OSDI_ExtensionUtil as E;

class PersonBasic {

  /**
   * This hook comes early enough in the merge process that the two contacts
   * still have their email addresses unchanged. We compare these emails to
   * determine whether the records need to be flagged.
   */
  public function alterLocationMergeData(&$blocksDAO, $mainId, $otherId, $migrationInfo) {
    /** @var \Civi\Osdi\LocalObject\PersonBasic $dupePerson */
    /** @var \Civi\Osdi\LocalObject\PersonBasic $keptPerson */

    $keptPerson =
      \Civi\OsdiClient::container()->make('LocalObject', 'Person', $mainId)->loadOnce();

    $dupePerson =
      \Civi\OsdiClient::container()->make('LocalObject', 'Person', $otherId)->loadOnce();

    $keptPersonEmail = $keptPerson->emailEmail->get();
    $dupePersonEmail = $dupePerson->emailEmail->get();

    if (
      empty($keptPersonEmail) || empty($dupePersonEmail)
      || $keptPersonEmail === $dupePersonEmail
    ) {
      return;
    }

    $message = "Contact #$otherId ($dupePersonEmail) was merged into contact "
      . "#$mainId ($keptPersonEmail) on CiviCRM. Due to limitations of the "
      . "Action Network API, we cannot automatically merge the corresponding "
      . "people on Action Network. Please merge them manually, and then mark "
      . "this flag as resolved.";

    $syncer = self::getPersonSingleSyncer();

    foreach (['kept' => $keptPerson, 'dupe' => $dupePerson] as $i => $localPerson) {
      $flagCreate = OsdiFlag::create(FALSE)
        ->addValue('contact_id', $localPerson->getId())
        ->addValue('status', OsdiFlag::STATUS_ERROR)
        ->addValue('flag_type', 'merge_incomplete')
        ->addValue('message', $message);

      $pair = $syncer->toLocalRemotePair($localPerson);
      $pair->setOrigin($pair::ORIGIN_LOCAL);
      $syncer->fetchOldOrFindNewMatch($pair);
      $remotePerson = $pair->getRemoteObject();
      if ($remotePerson) {
        $flagCreate->addValue('remote_object_id', $remotePerson->getId());
      }

      $flagCreate->execute();
    }
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

  public function merge($type, &$data, $idBeingKept = NULL, $idBeingDeleted = NULL, $tables = NULL) {
    // // it would be nice to use the following, but doing so is deprecated,
    // // according to https://github.com/civicrm/civicrm-core/blob/5e794c90b9c3132f9e792b513e9434721d825712/CRM/Dedupe/Merger.php#L236
    // // which was introduced by eileen @ https://github.com/civicrm/civicrm-core/commit/e3e87c738e8276dbfd4d3a0f9d74302896074558#diff-fe60c89ebe94bbd40114d135459371cc53708db1f2952ade6c0ce9b3468c4dabR220
    //if ('cidRefs' === $type) {
    //  unset($data['civicrm_osdi_flag']);
    //  return;
    //}

    if ('sqls' !== $type) {
      return;
    }

    foreach ($data as $key => $query) {
      if (strpos($query, 'civicrm_osdi_flag') !== FALSE) {
        unset($data[$key]);
      }
    }

    \Civi::$statics['osdiClient.inProgress']['delete'][] =
      \Civi\OsdiClient::container()->make('LocalObject', 'Person', $idBeingDeleted);
    \Civi::$statics['osdiClient.inProgress']['delete'][] =
      \Civi\OsdiClient::container()->make('LocalObject', 'Person', $idBeingKept);

    $queue = \Civi\Osdi\Queue::getQueue();

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
  }

  public static function syncCreationFromQueue(
    \CRM_Queue_TaskContext $context,
    array $serializedPerson
  ) {
    $localPerson = \Civi\OsdiClient::container()->make('LocalObject', 'Person');
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
    $localPerson = \Civi\OsdiClient::container()->make('LocalObject', 'Person');
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
    $localPerson = \Civi\OsdiClient::container()->make('LocalObject', 'Person');
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
    $localPerson = \Civi\OsdiClient::container()->make('LocalObject', 'Person', $contactId);
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
        $firstNameFromHook = (string) ($entityArrayFromHook['first_name'] ?? '');
        $lastNameFromUs = (string) $objectFromUs->lastName->get();
        $lastNameFromHook = (string) ($entityArrayFromHook['last_name'] ?? '');
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
    $localPerson = \Civi\OsdiClient::container()->make('LocalObject', 'Person', $dao->id);
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
    $remoteSystem = \Civi\OsdiClient::container()->getSingle('RemoteSystem', 'ActionNetwork');
    $syncer = \Civi\OsdiClient::container()->getSingle('SingleSyncer', 'Person', $remoteSystem);
    return $syncer;
  }

  private static function getTaggingBatchSyncer(): BatchSyncerInterface {
    $remoteSystem = \Civi\OsdiClient::container()->getSingle('RemoteSystem', 'ActionNetwork');
    $singleSyncer = \Civi\OsdiClient::container()->getSingle('SingleSyncer', 'Tagging', $remoteSystem);
    $batchSyncer = \Civi\OsdiClient::container()->getSingle('BatchSyncer', 'Tagging', $singleSyncer);
    return $batchSyncer;
  }

}
