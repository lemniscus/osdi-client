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

  public function batchSyncFromRemote(): ?string {
    if (!Director::acquireLock('Batch AN->Civi person sync')) {
      return NULL;
    }

    try {
      $cutoff = $this->getOrCreateActNetModTimeHorizon();
      \Civi::settings()->set('osdiClient.personBatchSyncActNetModTimeCutoff', $cutoff);
      $syncStartTime = time();

      $countText = $this->findAndSyncRemoteUpdatesAsNeeded($cutoff);

      $elapsedSeconds = time() - $syncStartTime;
      Logger::logDebug("Finished batch AN->Civi person sync; $countText; "
        . "time: $elapsedSeconds seconds");

      $newCutoff = RemoteSystem::formatDateTime($syncStartTime - 30);
      \Civi::settings()
        ->set('osdiClient.personBatchSyncActNetModTimeCutoff', $newCutoff);
      Logger::logDebug("Setting horizon for next AN->Civi person sync to $newCutoff");
    }
    finally {
      Director::releaseLock();
    }

    return $countText;
  }

  public function batchSyncFromLocal(): ?string {
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

  protected function findAndSyncRemoteUpdatesAsNeeded($cutoff): string {
    $searchResults = $this->getSingleSyncer()->getRemoteSystem()->find('osdi:people', [
      [
        'modified_date',
        'gt',
        $cutoff,
      ],
    ]);

    $totalCount = $currentCount = 0;
    $counts = array_fill_keys([Sync::SUCCESS, Sync::ERROR, 'skipped'], 0);

    foreach ($searchResults as $remotePerson) {
      $totalCount = $searchResults->rawCurrentCount();
      $orMore = ($totalCount > 24) ? '+' : '';
      $countFormat = '#%' . strlen($totalCount) . "d/$totalCount$orMore: ";

      $remoteId = $remotePerson->getId();
      Logger::logDebug(
        sprintf($countFormat, ++$currentCount) .
        "Considering AN person id $remoteId" .
        ', mod ' . $remotePerson->modifiedDate->get() .
        ', ' . $remotePerson->emailAddress->get());

      if ('2c8f5384-0476-4aaa-aee4-471805c48a54' === $remoteId) {
        Logger::logDebug("Skipping    AN id $remoteId: special record");
        $counts['skipped']++;
        continue;
      }

      $pair = NULL;
      try {
        $pair = $this->getSingleSyncer()->matchAndSyncIfEligible($remotePerson);
        $syncResult = $pair->getResultStack()->getLastOfType(Sync::class);
      }
      catch (\Throwable $e) {
        $syncResult = new Sync(NULL, NULL, Sync::ERROR, $e->getMessage());
        Logger::logError($e->getMessage(), ['exception' => $e]);
      }

      $statusCode = $syncResult->getStatusCode() ?: 'no code';
      $counts[$statusCode] = ($counts[$statusCode] ?? 0) + 1;

      $codeAndMessage = $statusCode .
        ($syncResult->getMessage() ? (' - ' . $syncResult->getMessage()) : '');

      Logger::logDebug("Result for AN id $remoteId: $codeAndMessage");

      if ($syncResult->isError()) {
        Logger::logError($codeAndMessage, $pair);
      }

    }

    foreach ($counts as $k => $v) {
      $counts[$k] = "$k: $v";
    }
    return "total: $totalCount; " . implode('; ', $counts);
  }

  protected function findAndSyncLocalUpdatesAsNeeded($cutoff): array {
    $civiContacts = $this->getCandidateLocalContacts($cutoff);

    $totalCount = count($civiContacts);
    $countFormat = '(%' . strlen($totalCount) . "d/$totalCount) ";
    $counts = array_fill_keys([Sync::SUCCESS, Sync::ERROR, 'skipped'], 0);
    $currentCount = 0;

    Logger::logDebug("Civi->AN person sync: $totalCount to consider");

    foreach ($civiContacts as $contact) {
      ++$currentCount;

      if (strtotime($contact['contact.modified_date']) ===
        $contact['sync_state.local_post_sync_modified_time']
      ) {
        $upToDate[] = $contact['contact_id'];
        $counts['skipped']++;
        continue;
      }

      if ($upToDate ?? FALSE) {
        Logger::logDebug('Civi Ids already up to date: ' . implode(', ', $upToDate));
        $upToDate = [];
      }

      $localPerson = OsdiClient::container()
        ->make('LocalObject', 'Person', $contact['contact_id'])
        ->loadOnce();

      $localPersonId = $localPerson->getId();

      Logger::logDebug(sprintf($countFormat, $currentCount) .
        "Considering Civi id $localPersonId, mod " .
        $localPerson->modifiedDate->get() . ', ' .
        $localPerson->emailEmail->get());

      try {
        $pair = $this->getSingleSyncer()->matchAndSyncIfEligible($localPerson);
        $syncResult = $pair->getLastResultOfType(Sync::class);
        $statusCode = $syncResult->getStatusCode() ?: 'no code';
        $counts[$statusCode] = ($counts[$statusCode] ?? 0) + 1;

        $context = $syncResult->getContext();
        $codeAndMessage = $statusCode .
          ($syncResult->getMessage() ? (' - ' . $syncResult->getMessage()) : '') .
          ($context ? (PHP_EOL . print_r($context, TRUE)) : '');

        Logger::logDebug("Result for Civi id $localPersonId: $codeAndMessage");
      }

      catch (\Throwable $e) {
        $counts[Sync::ERROR]++;
        Logger::logError(sprintf($countFormat, $currentCount) . $e->getMessage(), ['exception' => $e]);
      }
    }

    if ($upToDate ?? FALSE) {
      Logger::logDebug('Civi Ids already up to date: ' . implode(', ', $upToDate));
    }

    foreach ($counts as $k => $v) {
      $counts[$k] = "$k: $v";
    }
    $countText = "total: $totalCount; " . implode('; ', $counts);
    return [$contact['contact.modified_date'] ?? NULL, $countText];
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

    Logger::logDebug("Horizon for AN->Civi person sync set to $cutoff");
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
      $maxModTime = \Civi\Api4\OsdiPersonSyncState::get(FALSE)
        ->addSelect('MAX(local_pre_sync_modified_time) AS maximum')
        ->addWhere('sync_origin', '=', PersonSyncState::ORIGIN_LOCAL)
        ->execute()->single()['maximum'];

      Logger::logDebug('Maximum pre-sync mod time from previous Civi->AN syncs: '
        . ($maxModTime ?: 'NULL'));

      $cutoff = $maxModTime ?: date('Y-m-d H:i:s', time() - 60);
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
