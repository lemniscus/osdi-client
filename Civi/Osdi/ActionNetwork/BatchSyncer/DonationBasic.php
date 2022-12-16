<?php

namespace Civi\Osdi\ActionNetwork\BatchSyncer;

use Civi\Api4\Contribution;
use Civi\Osdi\BatchSyncerInterface;
use Civi\Osdi\Factory;
use Civi\Osdi\LocalObject\Donation as LocalDonation;
use Civi\Osdi\Logger;
use Civi\Osdi\Result\Sync;
use Civi\Osdi\SingleSyncerInterface;

class DonationBasic implements BatchSyncerInterface {

  private ?SingleSyncerInterface $singleSyncer;

  public function __construct(SingleSyncerInterface $singleSyncer = NULL) {
    $this->setSingleSyncer($singleSyncer);
  }

  public function getSingleSyncer(): ?SingleSyncerInterface {
    return $this->singleSyncer;
  }

  public function setSingleSyncer(SingleSyncerInterface $singleSyncer): void {
    $this->singleSyncer = $singleSyncer;
  }

  public function batchSyncFromRemote(): ?int {

    $syncStartTime = time();
    $cutoff = $this->getCutOff('remote');
    $searchResults = $this->findAndSyncNewRemoteDonations($cutoff);

    Logger::logDebug('Finished batch AN->Civi sync; count: ' .
      $searchResults->rawCurrentCount() . '; time: ' . (time() - $syncStartTime)
      . ' seconds');

    return $searchResults->rawCurrentCount();
  }

  /**
   * Return a date 1 week before the last Contribution that was synced in the given direction.
   *
   * Defaults to 1 week ago today, if none.
   *
   * @return string Y-m-d format date.
   */
  protected function getCutOff(string $source): string {
    if (!in_array($source, ['remote', 'local'])) {
      throw new \InvalidArgumentException("getCutOff requires either 'remote' or 'local' as its argument.");
    }

    $result = \Civi\Api4\Contribution::get(FALSE)
    ->addSelect('MAX(receive_date) AS last_receive_date')
    ->addJoin('OsdiDonationSyncState AS osdi_donation_sync_state', 'INNER', NULL, ['id', '=', 'osdi_donation_sync_state.contribution_id'])
    ->addWhere('osdi_donation_sync_state.source', '=', $source)
    ->execute()->first();

    $latest = $result ? $result['last_receive_date'] : date('Y-m-d');

    // $cutoff = date('Y-m-d', strtotime("$latest - 1 week"));
    $cutoff = date('Y-m-d', strtotime("$latest - 2 day"));
    Logger::logDebug("Using $cutoff for $source donation sync");
    return $cutoff;
  }

  public function batchSyncFromLocal(): ?int {
    $cutoff = $this->getCutOff('local');
    $count = $this->findAndSyncNewLocalDonations($cutoff);
    return $count;
  }

  protected function findAndSyncNewRemoteDonations(string $cutoff): \Civi\Osdi\ActionNetwork\RemoteFindResult {
    $searchResults = $this->singleSyncer->getRemoteSystem()->find('osdi:donations', [
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
        $pair = $this->singleSyncer->matchAndSyncIfEligible($remoteDonation);
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

  private function findAndSyncNewLocalDonations(string $cutoff): int {

    // @todo
    // select contributions.* from contribs where date_received > $cutoff and not exists (select DonationSyncState where contribs.id = contribution_id)
    $contributions = Contribution::get(FALSE)
    ->addWhere('receive_date', '>=', $cutoff)
    ->addWhere('contribution_status_id:name', '=', 'Completed')
    ->addJoin(
      'OsdiDonationSyncState AS Contribution_OsdiDonationSyncState_contribution_id_01',
      'EXCLUDE',
      [ 'id', '=', 'Contribution_OsdiDonationSyncState_contribution_id_01.contribution_id' ]
    )
    ->execute();

    foreach ($contributions as $contribution) {
      Logger::logDebug("Considering Contribution id {$contribution['id']}, created {$contribution['receive_date']}");

      try {
        // artfulrobot: @todo all cases are eligible unless I were to implement getSyncEligibility
        $localDonation = LocalDonation::fromId($contribution['id']);
        $pair = $this->singleSyncer->matchAndSyncIfEligible($localDonation);
        $syncResult = $pair->getResultStack()->getLastOfType(Sync::class);
        $codeAndMessage = $syncResult->getStatusCode() . ' - ' . $syncResult->getMessage();
        Logger::logDebug("Result for Contribution {$contribution['id']}: $codeAndMessage");
      }
      catch (\Throwable $e) {
        $syncResult = new Sync(NULL, NULL, NULL, $e->getMessage());
        Logger::logError($e->getMessage(), ['exception' => $e]);
      }

    }

    return 0;
  }

}

