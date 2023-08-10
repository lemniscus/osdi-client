<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer;

use Civi\Api4\OsdiDonationSyncState;
use Civi\Osdi\ActionNetwork\Object\Donation as RemoteDonation;
use Civi\Osdi\DonationSyncState;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\LocalObject\DonationBasic as LocalDonation;
use Civi\Osdi\LocalObjectInterface;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\Result\FetchOldOrFindNewMatch as OldOrNewMatchResult;
use Civi\Osdi\Result\Sync;
use Civi\Osdi\Result\SyncEligibility;
use Civi\Osdi\SingleSyncerInterface;
use Civi\OsdiClient;

class DonationBasic extends AbstractSingleSyncer implements SingleSyncerInterface {

  protected RemoteSystemInterface $remoteSystem;

  public function __construct(?RemoteSystemInterface $remoteSystem = NULL) {
    $this->remoteSystem = $remoteSystem ?? OsdiClient::container()->getSingle(
      'RemoteSystem', 'ActionNetwork');
    $this->registryKey = 'Donation';
  }

  protected function getLocalObjectClass(): string {
    return LocalDonation::class;
  }

  protected function getRemoteObjectClass(): string {
    return RemoteDonation::class;

  }

  public function makeLocalObject($id = NULL): LocalObjectInterface {
    return OsdiClient::container()->make('LocalObject', 'Donation', $id);
  }

  public function makeRemoteObject($id = NULL): RemoteObjectInterface {
    $system = $this->getRemoteSystem();
    $donation = OsdiClient::container()->make('OsdiObject', 'osdi:donations', $system);
    if (!is_null($id)) {
      $donation->setId($id);
    }
    return $donation;
  }

  protected function saveSyncStateIfNeeded(LocalRemotePair $pair) {
    $remoteObject = $pair->getRemoteObject();
    $localObject = $pair->getLocalObject();

    if (empty($localObject)) {
      [$localID, $localIdOp] = [NULL, 'IS NULL'];
    }
    else {
      [$localID, $localIdOp] = [$localObject->getId(), '='];
    }

    if (empty($remoteObject)) {
      [$remoteID, $remoteIdOp] = [NULL, 'IS NULL'];
    }
    else {
      [$remoteID, $remoteIdOp] = [$remoteObject->getId(), '='];
    }

    if (empty($localID) && empty($remoteID)) {
      // We require at least one to save a sync state
      return NULL;
    }

    $getAction = OsdiDonationSyncState::get(FALSE)
      ->addWhere('remote_donation_id', $remoteIdOp, $remoteID)
      ->addWhere('contribution_id', $localIdOp, $localID);

    $syncProfileId = OsdiClient::container()->getSyncProfileId();
    if ($syncProfileId) {
      $getAction->addWhere('sync_profile_id', '=', $syncProfileId);
    }

    $dssId = $getAction->execute()->first()['id'] ?? NULL;

    if (!$dssId) {
      $syncResult = $pair->getLastResultOfType(Sync::class);
      $status = $syncResult ? $syncResult->getStatusCode() : NULL;

      $dssId = OsdiDonationSyncState::create(FALSE)
        ->setValues([
          'remote_donation_id' => $remoteID,
          'contribution_id'    => $localID,
          'sync_profile_id'    => $syncProfileId,
          'source'             => $pair->getOrigin(),
          'sync_time'          => date('Y-m-d H:i:s'),
          'sync_status'        => $status,
        ])
        ->execute()->first()['id'] ?? NULL;
    }
    return (new DonationSyncState())->setId($dssId);
  }

  protected function getSavedMatch(LocalRemotePair $pair): ?DonationSyncState {
    if ($pair->isOriginRemote()) {
      $action = OsdiDonationSyncState::get(FALSE)
        ->addWhere('remote_donation_id', '=',
          $pair->getRemoteObject()->getId());
    }
    else {
      $action = OsdiDonationSyncState::get(FALSE)
        ->addWhere('contribution_id', '=',
          $pair->getLocalObject()->getId());
    }
    $record = $action->execute()->first();
    return $record ? DonationSyncState::fromArray($record) : NULL;
  }

  /**
   * We are eligible unless a sync state exists or we've found a twin donation.
   * Note, we don't care about which SyncProfile is in use -- we never want to
   * create multiple copies of a donation on the local system, and we don't have
   * anyone syncing to multiple remote systems yet.
   */
  protected function getSyncEligibility(LocalRemotePair $pair): SyncEligibility {
    $result = new SyncEligibility();
    $origin = $pair->getOrigin();
    $originObjectId = $pair->getOriginObject()->getId();

    /** @var \Civi\Osdi\Result\FetchOldOrFindNewMatch $matchResult */
    $matchResult = $pair->getLastResultOfType(OldOrNewMatchResult::class);
    $matchStatus = $matchResult->getStatusCode();

    if (OldOrNewMatchResult::NO_MATCH_FOUND === $matchStatus) {
      $result->setStatusCode(SyncEligibility::ELIGIBLE);
      $result->setMessage("Donation from $origin ($originObjectId) is eligible for sync");
    }
    elseif (OldOrNewMatchResult::FETCHED_SAVED_MATCH === $matchStatus) {
      $result->setStatusCode(SyncEligibility::INELIGIBLE);
      $result->setMessage("Donation from $origin ($originObjectId) has already been synced");
      $result->setContext($matchResult->getSavedMatch());
    }
    elseif (OldOrNewMatchResult::FOUND_NEW_MATCH === $matchStatus) {
      $result->setStatusCode(SyncEligibility::INELIGIBLE);
      $result->setMessage("Donation from $origin ($originObjectId) already has a match by person, timestamp and amount");
    }
    else {
      throw new InvalidArgumentException('Unexpected status code from %s',
        OldOrNewMatchResult::class);
    }

    $pair->getResultStack()->push($result);
    return $result;
  }

}

