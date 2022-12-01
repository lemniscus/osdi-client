<?php

namespace Civi\Osdi\ActionNetwork\BatchSyncer;

use Civi\Osdi\ActionNetwork\RemoteSystem;
use Civi\Osdi\BatchSyncerInterface;
use Civi\Osdi\LocalObject\Donation as LocalDonation;
use Civi\Osdi\Logger;
use Civi\Osdi\DonationSyncState;
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

  // @todo there's a lot in here copied from the person batch syncer.
  // if that turns out to be a good pattern, it should be extracted for code reuse.
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
   *
   */
  protected function getCutOff(string $source): string {
    if (!in_array($source, ['remote', 'local'])) {
      throw new \InvalidArgumentException("getCutOff requires either 'remote' or 'local' as its argument.");
    }

    $result = \Civi\Api4\Contribution::get(FALSE)
    ->addSelect('MAX(receive_date) last_receive_date')
    ->addJoin('OsdiDonationSyncState AS osdi_donation_sync_state', 'INNER', ['id', '=', 'osdi_donation_sync_state.contribution_id'])
    ->addWhere('osdi_donation_sync_state.source', '=', $source)
    ->execute()->first();

    $latest = $result ? $result['last_receive_date'] : date('Y-m-d');

    $cutoff = date('Y-m-d', strtotime("$latest - 1 week"));
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

    return 0;
  }

}

