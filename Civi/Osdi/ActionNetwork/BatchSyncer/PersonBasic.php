<?php

namespace Civi\Osdi\ActionNetwork\BatchSyncer;

use Civi\Osdi\ActionNetwork\RemoteSystem;
use Civi\Osdi\BatchSyncerInterface;
use Civi\Osdi\Director;
use Civi\Osdi\Logger;
use Civi\Osdi\PersonSyncState;
use Civi\Osdi\Result\Sync;
use Civi\Osdi\SingleSyncerInterface;
use Civi\OsdiClient;

class PersonBasic implements BatchSyncerInterface {

  private ?SingleSyncerInterface $singleSyncer;

  public function getSingleSyncer(): ?SingleSyncerInterface {
    if (!$this->singleSyncer) {
      $this->singleSyncer = OsdiClient::container()->getSingle(
        'SingleSyncer', 'Person');
    }
    return $this->singleSyncer;
  }

  public function setSingleSyncer(SingleSyncerInterface $singleSyncer): void {
    $this->singleSyncer = $singleSyncer;
  }

  public function __construct(SingleSyncerInterface $singleSyncer = NULL) {
    $this->singleSyncer = $singleSyncer;
  }

  public function batchSyncFromRemote(): ?int {
    if (!Director::acquireLock('Batch AN->Civi person sync')) {
      return NULL;
    }

    try {
      $cutoff = $this->getOrCreateActNetModTimeHorizon();
      \Civi::settings()->set('osdiClient.personBatchSyncActNetModTimeCutoff', $cutoff);
      $syncStartTime = time();

      $searchResults = $this->findAndSyncRemoteUpdatesAsNeeded($cutoff);

      $elapsedSeconds = time() - $syncStartTime;
      Logger::logDebug('Finished batch AN->Civi person sync; count: ' .
        $searchResults->rawCurrentCount() . '; time: ' . ($elapsedSeconds)
        . ' seconds');

      $newCutoff = RemoteSystem::formatDateTime(strtotime($syncStartTime) - 30);
      \Civi::settings()
        ->set('osdiClient.personBatchSyncActNetModTimeCutoff', $newCutoff);
      Logger::logDebug("Setting horizon for next AN->Civi person sync to $newCutoff");
    }
    finally {
      Director::releaseLock();
    }

    return $searchResults->rawCurrentCount();
  }

  public function batchSyncFromLocal(): ?int {
    if (!Director::acquireLock('Batch Civi->AN person sync')) {
      return NULL;
    }

    try {
      $cutoff = $this->getOrCreateLocalModTimeHorizon();
      Logger::logDebug("Horizon for Civi->AN person sync set to $cutoff");
      $syncStartTime = time();

      [$mostRecentPreSyncModTime, $count]
        = $this->findAndSyncLocalUpdatesAsNeeded($cutoff);

      Logger::logDebug('Finished batch Civi->AN person sync; count: ' . $count
        . '; time: ' . (time() - $syncStartTime) . ' seconds');

      Logger::logDebug('The most recent modification time of a Civi contact in this' .
        ' sync is ' . ($mostRecentPreSyncModTime ?: 'NULL'));

      $newCutoff = $mostRecentPreSyncModTime ?: date('Y-m-d H:i:s', $syncStartTime - 1);
      \Civi::settings()->set('osdiClient.syncJobCiviModTimeCutoff', $newCutoff);
      Logger::logDebug("Setting horizon for next Civi->AN person sync to $newCutoff");
    }
    finally {
      Director::releaseLock();
    }

    return $count;
  }

  protected function findAndSyncRemoteUpdatesAsNeeded($cutoff): \Civi\Osdi\ActionNetwork\RemoteFindResult {
    $searchResults = $this->getSingleSyncer()->getRemoteSystem()->find('osdi:people', [
      [
        'modified_date',
        'gt',
        $cutoff,
      ],
    ]);

    $currentCount = 0;

    foreach ($searchResults as $remotePerson) {
      $totalCount = $searchResults->rawCurrentCount();
      $orMore = ($totalCount > 24) ? '+' : '';
      $countFormat = '#%' . strlen($totalCount) . "d/$totalCount$orMore: ";

      Logger::logDebug(
        sprintf($countFormat, ++$currentCount) .
        'Considering AN id ' . $remotePerson->getId() .
        ', mod ' . $remotePerson->modifiedDate->get() .
        ', ' . $remotePerson->emailAddress->get());

      if ('2c8f5384-0476-4aaa-aee4-471805c48a54' === $remotePerson->getId()) {
        Logger::logDebug('Skipping    AN id ' . $remotePerson->getId() .
          ': special record');
        continue;
      }

      try {
        $pair = $this->getSingleSyncer()->matchAndSyncIfEligible($remotePerson);
        $syncResult = $pair->getResultStack()->getLastOfType(Sync::class);
      }
      catch (\Throwable $e) {
        $syncResult = new Sync(NULL, NULL, NULL, $e->getMessage());
        Logger::logError($e->getMessage(), ['exception' => $e]);
      }

      $codeAndMessage = $syncResult->getStatusCode() . ' - ' .
        ($syncResult->getMessage() ? ' - ' . $syncResult->getMessage() : '');
      Logger::logDebug('Result for  AN id ' . $remotePerson->getId() .
        ": $codeAndMessage");
      if ($syncResult->isError()) {
        Logger::logError($codeAndMessage, $pair);
      }
    }

    return $searchResults;
  }

  protected function findAndSyncLocalUpdatesAsNeeded($cutoff): array {
    $civiContacts = $this->getCandidateLocalContacts($cutoff);

    $totalCount = count($civiContacts);
    $countFormat = '(%' . strlen($totalCount) . "d/$totalCount) ";
    $currentCount = 0;

    Logger::logDebug("Civi->AN person sync: $totalCount to consider");

    foreach ($civiContacts as $i => $contact) {
      if (strtotime($contact['contact.modified_date']) ===
        $contact['sync_state.local_post_sync_modified_time']
      ) {
        $upToDate[] = $contact['contact_id'];
        continue;
      }

      if ($upToDate ?? FALSE) {
        Logger::logDebug('Civi Ids already up to date: ' . implode(', ', $upToDate));
        $upToDate = [];
      }

      $localPerson = OsdiClient::container()
        ->make('LocalObject', 'Person', $contact['contact_id'])
        ->loadOnce();

      Logger::logDebug(sprintf($countFormat, ++$currentCount) .
        'Considering Civi id ' . $localPerson->getId() .
        ', mod ' . $localPerson->modifiedDate->get() .
        ', ' . $localPerson->emailEmail->get());

      $pair = $this->getSingleSyncer()->matchAndSyncIfEligible($localPerson);
      $syncResult = $pair->getResultStack()->getLastOfType(Sync::class);
      Logger::logDebug('Result for  Civi id ' . $localPerson->getId() .
        ': ' . $syncResult->getStatusCode() . ' - ' . $syncResult->getMessage()
        . PHP_EOL . print_r($syncResult->getContext(), TRUE));
    }

    if ($upToDate ?? FALSE) {
      Logger::logDebug('Civi Ids already up to date: ' . implode(', ', $upToDate));
    }

    $count = ($i ?? -1) + 1;
    return [$contact['contact.modified_date'] ?? NULL, $count];
  }

  protected function getOrCreateActNetModTimeHorizon() {
    $cutoff = \Civi::settings()
      ->get('osdiClient.personBatchSyncActNetModTimeCutoff');
    $cutoffWasRetrievedFromPreviousSync = !empty($cutoff);
    Logger::logDebug('Horizon time was ' .
      ($cutoffWasRetrievedFromPreviousSync ? '' : 'not ') .
      'retrieved from the previous sync'
    );

    if (!$cutoffWasRetrievedFromPreviousSync) {
      $cutoffUnixTime = \Civi\Api4\OsdiPersonSyncState::get(FALSE)
        ->addSelect('MAX(remote_pre_sync_modified_time) AS maximum')
        ->addWhere('sync_origin', '=', PersonSyncState::ORIGIN_REMOTE)
        ->execute()->single()['maximum'];

      Logger::logDebug('Maximum pre-syc mod times from previous AN->Civi syncs: '
        . ($cutoffUnixTime ? RemoteSystem::formatDateTime($cutoffUnixTime) : 'NULL'));

      if (empty($cutoffUnixTime)) {
        $cutoffUnixTime = time() - 60;
      }

      $cutoffUnixTime--;
      $cutoff = RemoteSystem::formatDateTime($cutoffUnixTime);
    }

    Logger::logDebug("Horizon for AN->Civi sync set to $cutoff");
    return $cutoff;
  }

  protected function getOrCreateLocalModTimeHorizon() {
    $cutoff = \Civi::settings()
      ->get('osdiClient.syncJobCiviModTimeCutoff');
    $cutoffWasRetrievedFromPreviousSync = !empty($cutoff);
    Logger::logDebug('Horizon time was ' .
      ($cutoffWasRetrievedFromPreviousSync ? '' : 'not ') .
      'retrieved from the previous sync'
    );

    if (!$cutoffWasRetrievedFromPreviousSync) {
      $cutoffUnixTime = \Civi\Api4\OsdiPersonSyncState::get(FALSE)
        ->addSelect('MAX(local_pre_sync_modified_time) AS maximum')
        ->addWhere('sync_origin', '=', PersonSyncState::ORIGIN_LOCAL)
        ->execute()->single()['maximum'];

      Logger::logDebug('Maximum pre-sync mod time from previous Civi->AN syncs: '
        . ($cutoffUnixTime ? RemoteSystem::formatDateTime($cutoffUnixTime) : 'NULL'));

      if (empty($cutoffUnixTime)) {
        $cutoffUnixTime = time() - 60;
      }

      $cutoff = date('Y-m-d H:i:s', $cutoffUnixTime);
    }
    return $cutoff;
  }

  protected function getCandidateLocalContacts($cutoff): \Civi\Api4\Generic\Result {
    $civiContacts = \Civi\Api4\Email::get(FALSE)
      ->addSelect(
        'contact_id',
        'contact.modified_date',
        'sync_state.local_pre_sync_modified_time',
        'sync_state.local_post_sync_modified_time')
      ->addJoin('Contact AS contact', 'INNER')
      ->addJoin(
        'OsdiPersonSyncState AS sync_state',
        'LEFT',
        ['contact_id', '=', 'sync_state.contact_id'])
      ->addGroupBy('email')
      ->addOrderBy('contact.modified_date')
      ->addWhere('contact.modified_date', '>=', $cutoff)
      ->addWhere('is_primary', '=', TRUE)
      ->addWhere('contact.is_deleted', '=', FALSE)
      ->addWhere('contact.is_opt_out', '=', FALSE)
      ->addWhere('contact.contact_type', '=', 'Individual')
      ->execute();
    return $civiContacts;
  }

}
