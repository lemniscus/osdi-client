<?php

namespace Civi\Osdi\ActionNetwork\BatchSyncer;

use Civi\Osdi\ActionNetwork\RemoteSystem;
use Civi\Osdi\BatchSyncerInterface;
use Civi\Osdi\LocalObject\PersonBasic as LocalPerson;
use Civi\Osdi\Logger;
use Civi\Osdi\PersonSyncState;
use Civi\Osdi\Result\Sync;
use Civi\Osdi\SingleSyncerInterface;

class PersonBasic implements BatchSyncerInterface {

  private ?SingleSyncerInterface $singleSyncer;

  public function getSingleSyncer(): ?SingleSyncerInterface {
    return $this->singleSyncer;
  }

  public function setSingleSyncer(SingleSyncerInterface $singleSyncer): void {
    $this->singleSyncer = $singleSyncer;
  }

  public function __construct(SingleSyncerInterface $singleSyncer = NULL) {
    $this->singleSyncer = $singleSyncer;
  }

  public function batchSyncFromRemote(): ?int {
    Logger::logDebug('Batch AN->Civi person sync requested');

    if ($this->isLastProcessStillRunning()) {
      return NULL;
    }
    Logger::logDebug('Batch AN->Civi person sync process ID is ' . getmypid());

    if (is_null(\Civi::settings()->get('osdiClient.syncJobEndTime'))) {
      Logger::logDebug('Last sync job did not finish successfully');
    }

    $cutoff = $this->getOrCreateActNetModTimeHorizon();

    \Civi::settings()->add([
      'osdiClient.syncJobProcessId' => getmypid(),
      'osdiClient.syncJobStartTime' => time(),
      'osdiClient.syncJobEndTime' => NULL,
      'osdiClient.syncJobActNetModTimeCutoff' => $cutoff,
    ]);

    $syncStartTime = time();
    $searchResults = $this->findAndSyncRemoteUpdatesAsNeeded($cutoff);
    Logger::logDebug('Finished batch AN->Civi person sync; count: ' .
      $searchResults->rawCurrentCount() . '; time: ' . (time() - $syncStartTime)
      . ' seconds');

    $newCutoff = RemoteSystem::formatDateTime($syncStartTime - 30);
    Logger::logDebug("Setting horizon for next AN->Civi person sync to $newCutoff");

    \Civi::settings()->add([
      'osdiClient.syncJobProcessId' => NULL,
      'osdiClient.syncJobEndTime' => time(),
      'osdiClient.syncJobActNetModTimeCutoff' => $newCutoff,
    ]);

    return $searchResults->rawCurrentCount();
  }

  public function batchSyncFromLocal(): ?int {
    Logger::logDebug('Batch Civi->AN person sync requested');

    if ($this->isLastProcessStillRunning()) {
      return NULL;
    }
    Logger::logDebug('Batch Civi->AN person sync process ID is ' . getmypid());

    $cutoff = $this->getOrCreateLocalModTimeHorizon();
    Logger::logDebug("Horizon for Civi->AN person sync set to $cutoff");

    \Civi::settings()->add([
      'osdiClient.syncJobProcessId' => getmypid(),
      'osdiClient.syncJobStartTime' => time(),
      'osdiClient.syncJobEndTime' => NULL,
    ]);

    $syncStartTime = time();

    [$mostRecentPreSyncModTime, $count] = $this->findAndSyncLocalUpdatesAsNeeded($cutoff);
    Logger::logDebug('Finished batch Civi->AN sync; count: ' . $count);

    Logger::logDebug('The most recent modification time of a Civi contact in this' .
      ' sync is ' . ($mostRecentPreSyncModTime ?: 'NULL'));

    $newCutoff = $mostRecentPreSyncModTime ?: date('Y-m-d H:i:s', $syncStartTime - 1);
    Logger::logDebug("Setting horizon for next Civi->AN person sync to $newCutoff");

    \Civi::settings()->add([
      'osdiClient.syncJobProcessId' => NULL,
      'osdiClient.syncJobEndTime' => time(),
      'osdiClient.syncJobCiviModTimeCutoff' => $newCutoff,
    ]);

    return $count;
  }

  private function findAndSyncRemoteUpdatesAsNeeded($cutoff): \Civi\Osdi\ActionNetwork\RemoteFindResult {
    $searchResults = $this->singleSyncer->getRemoteSystem()->find('osdi:people', [
      [
        'modified_date',
        'gt',
        $cutoff,
      ],
    ]);

    foreach ($searchResults as $remotePerson) {
      Logger::logDebug('Considering AN id ' . $remotePerson->getId() .
        ', mod ' . $remotePerson->modifiedDate->get() .
        ', ' . $remotePerson->emailAddress->get());

      if ('2c8f5384-0476-4aaa-aee4-471805c48a54' === $remotePerson->getId()) {
        Logger::logDebug('Skipping    AN id ' . $remotePerson->getId() .
          ': special record');
        continue;
      }

      try {
        $pair = $this->singleSyncer->matchAndSyncIfEligible($remotePerson);
        $syncResult = $pair->getResultStack()->getLastOfType(Sync::class);
      }
      catch (\Throwable $e) {
        $syncResult = new Sync(NULL, NULL, NULL, $e->getMessage());
        Logger::logError($e->getMessage(), ['exception' => $e]);
      }

      $codeAndMessage = $syncResult->getStatusCode() . ' - ' . $syncResult->getMessage();
      Logger::logDebug('Result for  AN id ' . $remotePerson->getId() .
        ": $codeAndMessage");
      if ($syncResult->isError()) {
        Logger::logError($codeAndMessage, $syncResult->getContext());
      }
    }
    return $searchResults;
  }

  private function findAndSyncLocalUpdatesAsNeeded($cutoff): array {
    $civiEmails = \Civi\Api4\Email::get(FALSE)
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

    Logger::logDebug('Civi->AN sync: ' . $civiEmails->count() . ' to consider');

    foreach ($civiEmails as $i => $emailRecord) {
      if (strtotime($emailRecord['contact.modified_date']) ===
        $emailRecord['sync_state.local_post_sync_modified_time']
      ) {
        $upToDate[] = $emailRecord['contact_id'];
        continue;
      }

      if ($upToDate ?? FALSE) {
        Logger::logDebug('Civi Ids already up to date: ' . implode(', ', $upToDate));
        $upToDate = [];
      }

      $localPerson = (new LocalPerson($emailRecord['contact_id']))->loadOnce();

      Logger::logDebug('Considering Civi id ' . $localPerson->getId() .
        ', mod ' . $localPerson->modifiedDate->get() .
        ', ' . $localPerson->emailEmail->get());

      $pair = $this->singleSyncer->matchAndSyncIfEligible($localPerson);
      $syncResult = $pair->getResultStack()->getLastOfType(Sync::class);
      Logger::logDebug('Result for  Civi id ' . $localPerson->getId() .
        ': ' . $syncResult->getStatusCode() . ' - ' . $syncResult->getMessage()
        . PHP_EOL . print_r($syncResult->getContext(), TRUE));
    }

    if ($upToDate ?? FALSE) {
      Logger::logDebug('Civi Ids already up to date: ' . implode(', ', $upToDate));
    }

    $count = ($i ?? -1) + 1;
    return [$emailRecord['contact.modified_date'] ?? NULL, $count];
  }

  private function isLastProcessStillRunning(): bool {
    $lastJobPid = \Civi::settings()->get('osdiClient.syncJobProcessId');
    if ($lastJobPid && posix_getsid($lastJobPid) !== FALSE) {
      Logger::logDebug("Sync process ID $lastJobPid is still running; quitting new process");
      return TRUE;
    }
    return FALSE;
  }

  private function getOrCreateActNetModTimeHorizon() {
    $cutoff = \Civi::settings()
      ->get('osdiClient.syncJobActNetModTimeCutoff');
    $cutoffWasRetrievedFromPreviousSync = !empty($cutoff);
    Logger::logDebug('Horizon time was ' .
      ($cutoffWasRetrievedFromPreviousSync ? '' : 'not') .
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

  private function getOrCreateLocalModTimeHorizon() {
    $cutoff = \Civi::settings()
      ->get('osdiClient.syncJobCiviModTimeCutoff');
    $cutoffWasRetrievedFromPreviousSync = !empty($cutoff);
    Logger::logDebug('Horizon time was ' .
      ($cutoffWasRetrievedFromPreviousSync ? '' : 'not') .
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

}
