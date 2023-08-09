<?php

namespace Civi\Osdi\ActionNetwork\BatchSyncer;

use Civi\Api4\Contribution;
use Civi\Api4\OsdiDonationSyncState;
use Civi\Osdi\ActionNetwork\RemoteFindResult;
use Civi\Osdi\BatchSyncerInterface;
use Civi\Osdi\Director;
use Civi\Osdi\LocalObject\DonationBasic as LocalDonation;
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
   * @return int|null how many remote donations were processed
   */
  public function batchSyncFromRemote(): ?int {
    if (!Director::acquireLock('Batch AN->Civi donation sync')) {
      return NULL;
    }

    try {
      $syncStartTime = time();
      $cutoff = $this->getCutOff('remote');

      $searchResults = $this->findAndSyncNewRemoteDonations($cutoff);

      Logger::logDebug('Finished batch AN->Civi donation sync; count: ' .
        $searchResults->rawCurrentCount() . '; time: ' . (time() - $syncStartTime)
        . ' seconds');
    }
    finally {
      Director::releaseLock();
    }

    return $searchResults->rawCurrentCount();
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
    if (!in_array($source, ['remote', 'local'])) {
      throw new \InvalidArgumentException(
        "getCutOff requires either 'remote' or 'local' as its argument.");
    }

    $twoDaysAgo = "- 2 day";
    $latest = $this->getLatestSyncedContributionDate($source) ?? $twoDaysAgo;
    $cutoffUnixTime = min(strtotime($latest), strtotime($twoDaysAgo));
    $cutoff = $this->getSingleSyncer()
      ->getRemoteSystem()::formatDateTime($cutoffUnixTime);

    Logger::logDebug("Using horizon $cutoff for $source donation sync");
    return $cutoff;
  }

  public function batchSyncFromLocal(): ?int {
    if (!Director::acquireLock('Batch Civi->AN donation sync')) {
      return NULL;
    }

    try {
      $syncStartTime = time();
      $cutoff = $this->getCutOff('local');

      $count = $this->findAndSyncNewLocalDonations($cutoff);

      Logger::logDebug('Finished batch Civi->AN donation sync; count: ' . $count
        . '; time: ' . (time() - $syncStartTime) . ' seconds');
    }
    finally {
      Director::releaseLock();
    }

    return $count;
  }

  protected function findAndSyncNewRemoteDonations(string $cutoff): RemoteFindResult {
    $searchResults = $this->getSingleSyncer()->getRemoteSystem()->find('osdi:donations', [
      [
        'modified_date',
        'gt',
        $cutoff,
      ],
    ]);

    $syncStates = $this->getSyncStatesForRemoteDonations($searchResults);
    $idsFromSyncStates = $syncStates->column('remote_donation_id');
    $currentCount = 0;

    foreach ($searchResults as $remoteDonation) {
      $totalCount = $searchResults->rawCurrentCount();
      //$orMore = ($totalCount > 24) ? '+' : '';
      $orMore = '';
      $countFormat = '#%' . strlen($totalCount) . "d/$totalCount$orMore: ";
      $progress = sprintf($countFormat, ++$currentCount);

      Logger::logDebug(
        $progress .
        'Considering AN donation id ' . $remoteDonation->getId() .
        ', mod ' . $remoteDonation->modifiedDate->get());

      if (in_array($remoteDonation->getId(), $idsFromSyncStates)) {
        Logger::logDebug($progress .
          'Result for AN id ' . $remoteDonation->getId() . ': skipped (sync record already exists)');
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
        ($syncResult->getMessage() ? ' - ' . $syncResult->getMessage() : '');

      Logger::logDebug($progress .
        'Result for AN id ' . $remoteDonation->getId() . ": $codeAndMessage");

      if ($syncResult->isError()) {
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
    }

    return $searchResults;
  }

  protected function findAndSyncNewLocalDonations(string $cutoff): int {
    $contributions = $this->findNewLocalDonations($cutoff);

    $totalCount = count($contributions);
    $countFormat = '%' . strlen($totalCount) . "d/$totalCount: ";
    $currentCount = 0;

    Logger::logDebug("Civi->AN donation sync: $totalCount to consider");

    foreach ($contributions as $contribution) {
      $progress = sprintf($countFormat, ++$currentCount);
      Logger::logDebug($progress .
        "Considering Contribution id {$contribution['id']}, created {$contribution['receive_date']}");

      try {
        // todo avoid reloading from db? we already pulled the data
        $localDonation = LocalDonation::fromId($contribution['id']);
        $pair = $this->getSingleSyncer()->matchAndSyncIfEligible($localDonation);
        $syncResult = $pair->getResultStack()->getLastOfType(Sync::class);
        $codeAndMessage = $syncResult->getStatusCode() . ' - ' . $syncResult->getMessage();
        Logger::logDebug($progress .
          "Result for Contribution {$contribution['id']}: $codeAndMessage");
      }
      catch (\Throwable $e) {
        Logger::logError($e->getMessage(), ['exception' => $e]);
      }

    }

    return $currentCount;
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

  protected function getSyncStatesForRemoteDonations(
    RemoteFindResult $searchResults
  ): \Civi\Api4\Generic\Result {
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

    $searchResults->rewind();
    return $syncStates;
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

