<?php

namespace Civi\Osdi\ActionNetwork\BatchSyncer;

use Civi\Api4\Contribution;
use Civi\Osdi\ActionNetwork\RemoteFindResult;
use Civi\Osdi\BatchSyncerInterface;
use Civi\Osdi\Director;
use Civi\Osdi\LocalObject\DonationBasic as LocalDonation;
use Civi\Osdi\Logger;
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

      Logger::logDebug('Finished batch AN->Civi sync; count: ' .
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

    $result = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('MAX(receive_date) AS last_receive_date')
      ->addJoin(
        'OsdiDonationSyncState AS osdi_donation_sync_state',
        'INNER',
        NULL,
        ['id', '=', 'osdi_donation_sync_state.contribution_id'])
      ->addWhere('osdi_donation_sync_state.source', '=', $source)
      ->execute()->first();

    $latest = $result ? $result['last_receive_date'] : date('Y-m-d');

    $cutoff = date('Y-m-d', strtotime("$latest - 2 day"));
    Logger::logDebug("Using $cutoff for $source donation sync");
    return $cutoff;
  }

  public function batchSyncFromLocal(): ?int {
    if (!Director::acquireLock('Batch Civi->AN donation sync')) {
      return NULL;
    }

    try {
      $cutoff = $this->getCutOff('local');
      $count = $this->findAndSyncNewLocalDonations($cutoff);
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

    foreach ($searchResults as $remoteDonation) {
      Logger::logDebug('Considering AN id ' . $remoteDonation->getId() .
        ', mod ' . $remoteDonation->modifiedDate->get());

      try {
        $pair = $this->getSingleSyncer()->matchAndSyncIfEligible($remoteDonation);
        $syncResult = $pair->getResultStack()->getLastOfType(Sync::class);
      }
      catch (\Throwable $e) {
        $syncResult = new Sync(NULL, NULL, NULL, $e->getMessage());
        Logger::logError($e->getMessage(), ['exception' => $e]);
      }

      $codeAndMessage = $syncResult->getStatusCode() . ' - ' . $syncResult->getMessage();
      Logger::logDebug('Result for AN id ' . $remoteDonation->getId() . ": $codeAndMessage");
      if ($syncResult->isError()) {
        Logger::logError($codeAndMessage, $syncResult->getContext());
      }
    }
    return $searchResults;
  }

  protected function findAndSyncNewLocalDonations(string $cutoff): int {
    $contributions = $this->findNewLocalDonations($cutoff);
    foreach ($contributions as $contribution) {
      Logger::logDebug("Considering Contribution id {$contribution['id']}, created {$contribution['receive_date']}");

      try {
        // todo avoid reloading from db? we already pulled the data
        $localDonation = LocalDonation::fromId($contribution['id']);
        $pair = $this->getSingleSyncer()->matchAndSyncIfEligible($localDonation);
        $syncResult = $pair->getResultStack()->getLastOfType(Sync::class);
        $codeAndMessage = $syncResult->getStatusCode() . ' - ' . $syncResult->getMessage();
        Logger::logDebug("Result for Contribution {$contribution['id']}: $codeAndMessage");
      }
      catch (\Throwable $e) {
        Logger::logError($e->getMessage(), ['exception' => $e]);
      }

    }

    return 0;
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

}

