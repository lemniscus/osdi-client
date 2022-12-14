<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer\Donation;

use Civi\Api4\OsdiDonationSyncState;
use Civi\Osdi\LocalObject\Donation as LocalDonation;
use Civi\Osdi\Logger;
use Civi\Osdi\ActionNetwork\Object\Donation as RemoteDonation;
use Civi\Osdi\ActionNetwork\SingleSyncer\AbstractSingleSyncer;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Factory;
use Civi\Osdi\LocalObjectInterface;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\SingleSyncerInterface;
use Civi\Osdi\Result\SyncEligibility;

class DonationBasic extends AbstractSingleSyncer implements SingleSyncerInterface {

  protected RemoteSystemInterface $remoteSystem;

  public function __construct(RemoteSystemInterface $remoteSystem) {
    $this->remoteSystem = $remoteSystem;
  }

  protected function getLocalObjectClass(): string {
    return LocalDonation::class;
  }

  protected function getRemoteObjectClass(): string {
    return RemoteDonation::class;

  }

  public function makeLocalObject($id = NULL): LocalObjectInterface {
    return Factory::make('LocalObject', 'Donation', $id);
  }

  public function makeRemoteObject($id = NULL): RemoteObjectInterface {
    $system = $this->getRemoteSystem();
    $donation = Factory::make('OsdiObject', 'osdi:donations', $system);
    if (!is_null($id)) {
      $donation->setId($id);
    }
    return $donation;
  }

  protected function saveSyncStateIfNeeded(LocalRemotePair $pair) {
    $remoteID = $pair->getRemoteObject()->getId();
    $localID = $pair->getLocalObject()->getId();

    if (empty($localID) || empty($remoteID)) {
      // We require both to save a sync state
      return NULL;
    }

    $exists = OsdiDonationSyncState::get(FALSE)
    ->addWhere('remote_donation_id', '=', $remoteID)
    ->addWhere('contribution_id', '=', $localID)
    ->execute()->first();

    if (!$exists) {
      OsdiDonationSyncState::save(FALSE)
      ->setRecords([[
        'remote_donation_id' => $remoteID,
        'contribution_id'    => $localID,
        'sync_profile_id'    => 1,
        'source'             => $pair->getOrigin()
      ]])
      ->execute();
    }
    return NULL;
  }

}

