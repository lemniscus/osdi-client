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
    $emailsBeingUpdated = $blocksDAO['email']['update'] ?? NULL;
    $emailIds = [];
    foreach ($emailsBeingUpdated as $emailDAO) {
      $emailIds[] = $emailDAO->id;
    }
    if (!$emailIds) {
      return;
    }
    $emailGetResult = Email::get(FALSE)
      ->addWhere('id', 'IN', $emailIds)
      ->addWhere('is_primary', '=', TRUE)
      ->execute()->first();
    // this gets us the email that is being transferred to contact ID $mainId
    // -- we need to compare it to contact ID $mainId's current primary email
    // to determine whether primary email is changing
    $e = $emailGetResult;
  }

  public function daoPreDelete(PreDelete $event) {
    /** @var \CRM_Contact_DAO_Contact $dao */
    $dao = $event->object;

    if ($this->isCallComingFromInsideTheHouse('delete', ['id' => $dao->id])) {
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
    OsdiPersonSyncState::delete(FALSE)
      ->addWhere('contact_id', 'IN', [$idBeingKept, $idBeingDeleted])
      ->execute();

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

  protected function isCallComingFromInsideTheHouse(string $op, array $entityArrayFromHook): bool {
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
    if ($this->isCallComingFromInsideTheHouse('delete', ['id' => $dao->id])) {
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
