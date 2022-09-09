<?php

namespace Civi\Osdi\ActionNetwork\CrmEventResponder;

use Civi\Api4\OsdiPersonSyncState;
use Civi\Osdi\BatchSyncerInterface;
use Civi\Osdi\Factory;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\SingleSyncerInterface;
use CRM_OSDI_ExtensionUtil as E;
use Symfony\Component\EventDispatcher\Event;

class PersonBasic {

  public function daoPreDelete(Event $event) {
    /** @var \CRM_Contact_DAO_Contact $dao */
    $dao = $event->object;

    if ($this->isCallComingFromInsideTheHouse('delete', ['id' => $dao->id])) {
      return;
    }

    $contactAsArray = $this->makeLocalObjectArrayFromDao($dao);

    $task = new \CRM_Queue_Task(
      [static::class, 'syncDeletionFromQueue'],
      ['contact' => $contactAsArray],
      E::ts('Sync deletion of Contact id %1',
        [1 => $contactAsArray['id']])
    );

    $queue = \Civi\Osdi\Queue::getQueue();
    $queue->createItem($task, ['weight' => -10]);
  }

  public function merge(int $idBeingKept, int $idBeingDeleted) {
    OsdiPersonSyncState::delete(FALSE)
      ->addWhere('contact_id', 'IN', [$idBeingKept, $idBeingDeleted])
      ->execute();

    $queue = \Civi\Osdi\Queue::getQueue();

    $task = new \CRM_Queue_Task(
      [static::class, 'syncCreationFromQueue'],
      ['contact' => ['id' => $idBeingKept]],
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

  public static function syncCreationFromQueue(
    \CRM_Queue_TaskContext $context,
    array $serializedContact
  ) {
    $localPerson = Factory::make('LocalObject', 'Person');
    $localPerson->loadFromArray($serializedContact);
    if (count($serializedContact) === 1) {
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

  protected function makeLocalObjectArrayFromDao(\CRM_Contact_DAO_Contact $dao): array {
    $localPerson = Factory::make('LocalObject', 'Person', $dao->id);
    return $localPerson->loadOnce()->getAll();
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