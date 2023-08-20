<?php

namespace Civi\Osdi\ActionNetwork\BatchSyncer;

use Civi\Api4\Contribution;
use Civi\Api4\OsdiDonationSyncState;
use Civi\Osdi\ActionNetwork\RemoteFindResult;
use Civi\Osdi\BatchSyncerInterface;
use Civi\Osdi\Director;
use Civi\Osdi\Logger;
use Civi\Osdi\Result\Map;
use Civi\Osdi\Result\MatchResult;
use Civi\Osdi\Result\Sync;
use Civi\Osdi\SingleSyncerInterface;
use Civi\OsdiClient;

class DonationBasic implements BatchSyncerInterface {

  private ?SingleSyncerInterface $singleSyncer;

  public function __construct(SingleSyncerInterface $singleSyncer = NULL) {
    $this->setSingleSyncer($singleSyncer);
  }

  public function getSingleSyncer(): ?SingleSyncerInterface {
    if (!$this->singleSyncer) {
      $this->singleSyncer = OsdiClient::container()->getSingle(
        'SingleSyncer', 'Donation');
    }
    return $this->singleSyncer;
  }

  public function setSingleSyncer(?SingleSyncerInterface $singleSyncer): void {
    $this->singleSyncer = $singleSyncer;
  }

  /**
   * Find new Action Network donations since last sync; copy them into Civi.
   *
   * @return string|null how many remote donations were processed
   */
  public function batchSyncFromRemote(): ?string {
    if (!Director::acquireLock('Batch AN->Civi donation sync')) {
      return NULL;
    }

    try {
      $syncStartTime = time();
      $cutoff = $this->getCutOff('remote');

      $countText = $this->findAndSyncNewRemoteDonations($cutoff);

      $elapsedTime = time() - $syncStartTime;
      $stats = "$countText; time: $elapsedTime seconds";
      Logger::logDebug("Finished batch AN->Civi donation sync; $stats");
    }
    finally {
      Director::releaseLock();
    }

    return $stats;
  }

  /**
   * Return a date 2 days before the last Contribution/Donation that was synced
   * in the given direction. The 2-day window is intended to account for any
   * lag in Contributions/Donations posting/showing as "completed".
   *
   * Defaults to 2 days before today, if none.
   *
   * @return string Y-m-d format date.
   */
  protected function getCutOff(string $source): string {
    if ('remote' === $source) {
      $safetyWindow = $this->getSingleSyncer()
        ->getRemoteSystem()::formatDateTime(strtotime('- 2 day'));
      $cutoff = \Civi::settings()
        ->get('osdiClient.donationBatchSyncMaxRetrievedModTime')
        ?? $safetyWindow;
      $cutoff = min($cutoff, $safetyWindow);
    }

    elseif ('local' === $source) {
      $safetyWindow = "- 2 day";
      $latest = $this->getLatestSyncedContributionDate($source) ?? $safetyWindow;
      $cutoffUnixTime = min(strtotime($latest), strtotime($safetyWindow));
      $cutoff = date('Y-m-d H:i:s', $cutoffUnixTime);
    }

    else {
      throw new \InvalidArgumentException(
        "getCutOff requires either 'remote' or 'local' as its argument.");
    }

    Logger::logDebug("Using horizon $cutoff for $source donation sync");
    return $cutoff;
  }

  public function batchSyncFromLocal(): ?string {
    if (!Director::acquireLock('Batch Civi->AN donation sync')) {
      return NULL;
    }

    try {
      $syncStartTime = time();
      $cutoff = $this->getCutOff('local');

      $countText = $this->findAndSyncNewLocalDonations($cutoff);
      $message = "$countText; time: " . (time() - $syncStartTime) . ' seconds';

      Logger::logDebug('Finished batch Civi->AN donation sync; ' . $message);
    }
    finally {
      Director::releaseLock();
    }

    return $message;
  }

  protected function findAndSyncNewRemoteDonations(string $cutoff): string {
    /** @var \Civi\Osdi\ActionNetwork\RemoteFindResult $searchResults */
    $searchResults = $this->getSingleSyncer()->getRemoteSystem()->find('osdi:donations', [
      [
        'modified_date',
        'gt',
        $cutoff,
      ],
    ]);

    $idsFromSyncStates = $this->matchRemoteDonationsToSyncStates($searchResults);
    $totalCount = $currentCount = $successCount = $errorCount = $skippedCount = 0;

    foreach ($searchResults as $remoteDonation) {
      $totalCount = $searchResults->rawCurrentCount();
      $countFormat = '#%' . strlen($totalCount) . "d/$totalCount: ";
      $progress = sprintf($countFormat, ++$currentCount);

      $donationDate = $remoteDonation->modifiedDate->get();
      $maxDonationDate = max($donationDate, $maxDonationDate ?? $donationDate);

      $donationId = $remoteDonation->getId();
      Logger::logDebug($progress .
        "Considering AN donation id $donationId, mod $donationDate");

      if (in_array($donationId, $idsFromSyncStates)) {
        Logger::logDebug($progress .
          "Result for AN id $donationId: skipped (sync record already exists)");
        $skippedCount++;
        continue;
      }

      try {
        $pair = $this->getSingleSyncer()->matchAndSyncIfEligible($remoteDonation);
        $syncResult = $pair->getResultStack()->getLastOfType(Sync::class);
      }
      catch (\Throwable $e) {
        $syncResult = new Sync(NULL, NULL, Sync::ERROR, $e->getMessage());
        Logger::logError($e->getMessage(), ['exception' => $e]);
      }

      $codeAndMessage = $syncResult->getStatusCode() .
        ($syncResult->getMessage() ? (' - ' . $syncResult->getMessage()) : '');

      Logger::logDebug("{$progress}Result for AN id $donationId: $codeAndMessage");

      if ($syncResult->isError()) {
        $errorCount++;
        $errorIsWorthLogging = TRUE;
        $resultStack = $pair->getResultStack();

        $mapResult = $resultStack->getLastOfType(Map::class);
        if ($mapResult &&
          str_ends_with($mapResult->getMessage() ?? '', 'no LocalPerson match.')
        ) {
          $errorIsWorthLogging = FALSE;
        }

        else {
          $matchResult = $resultStack->getLastOfType(MatchResult::class);
          // don't bother doing a special log entry for Phantom Donors
          if ($matchResult &&
            $matchResult->isStatus(MatchResult::ERROR_INVALID_ID)
          ) {
            $errorIsWorthLogging = FALSE;
          }
        }

        if ($errorIsWorthLogging) {
          Logger::logError($codeAndMessage, $pair);
        }
      }

      else {
        $successCount++;
      }
    }

    if (isset($maxDonationDate)) {
      \Civi::settings()->set(
        'osdiClient.donationBatchSyncMaxRetrievedModTime', $maxDonationDate);
    }

    return "total: $totalCount; success: $successCount; error: $errorCount; skipped: $skippedCount";
  }

  protected function findAndSyncNewLocalDonations(string $cutoff): string {
    $contributions = $this->findNewLocalDonations($cutoff);

    $totalCount = count($contributions);
    $countFormat = '%' . strlen($totalCount) . "d/$totalCount: ";
    $currentCount = $successCount = $errorCount = 0;

    Logger::logDebug("Civi->AN donation sync: $totalCount to consider");

    foreach ($contributions as $contribution) {
      $progress = sprintf($countFormat, ++$currentCount);
      Logger::logDebug($progress .
        "Considering Contribution id {$contribution['id']}, created {$contribution['receive_date']}");

      try {
        // todo avoid reloading from db? we already pulled the data
        $localDonation = OsdiClient::container()
          ->make('LocalObject', 'Donation', $contribution['id'])
          ->loadOnce();
        $pair = $this->getSingleSyncer()->matchAndSyncIfEligible($localDonation);
        $syncResult = $pair->getResultStack()->getLastOfType(Sync::class);
        $syncResult->isError() ? $errorCount++ : $successCount++;
        $codeAndMessage = $syncResult->getStatusCode() . ' - ' . $syncResult->getMessage();
        Logger::logDebug($progress .
          "Result for Contribution {$contribution['id']}: $codeAndMessage");
      }
      catch (\Throwable $e) {
        $errorCount++;
        Logger::logError($e->getMessage(), ['exception' => $e]);
      }

    }

    return "total: $totalCount; success: $successCount; error: $errorCount";
  }

  protected function findNewLocalDonations(string $cutoff): \Civi\Api4\Generic\Result {
    return Contribution::get(FALSE)
      ->addWhere('receive_date', '>=', $cutoff)
      ->addWhere('contribution_status_id:name', '=', 'Completed')
      ->addJoin(
        'OsdiDonationSyncState AS donation_sync_state',
        'EXCLUDE',
        ['id', '=', 'donation_sync_state.contribution_id']
      )
      ->execute();
  }

  protected function matchRemoteDonationsToSyncStates(
    RemoteFindResult $searchResults
  ): array {
    $currentCount = 0;
    $remoteIds = [];
    Logger::logDebug('Loading AN donations');

    foreach ($searchResults as $remoteDonation) {
      $remoteIds[] = $remoteDonation->getId();
      $currentCount++;
      if (($currentCount % 25) == 0) {
        Logger::logDebug("$currentCount donations loaded from Action Network");
      }
    }
    if (($currentCount % 25) != 0) {
      Logger::logDebug("$currentCount donations loaded from Action Network");
    }

    $syncStates = OsdiDonationSyncState::get(FALSE)
      ->addWhere('remote_donation_id', 'IN', $remoteIds)
      ->execute();

    return $syncStates->column('remote_donation_id');
  }

  private function getLatestSyncedContributionDate(string $source) {
    $result = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('MAX(receive_date) AS last_receive_date')
      ->addJoin(
        'OsdiDonationSyncState AS osdi_donation_sync_state',
        'INNER',
        NULL,
        ['id', '=', 'osdi_donation_sync_state.contribution_id'])
      ->addWhere('osdi_donation_sync_state.source', '=', $source)
      ->execute()->first()['last_receive_date'] ?? NULL;

    return $result;
  }

}

