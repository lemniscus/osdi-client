<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer\Donation;

use Civi\Osdi\LocalObject\Donation as LocalDonation;
use Civi\Osdi\ActionNetwork\Object\Donation as RemoteDonation;
use Civi\Osdi\ActionNetwork\SingleSyncer\AbstractSingleSyncer;
use Civi\Osdi\Factory;
use Civi\Osdi\LocalObjectInterface;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\SingleSyncerInterface;

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
    $donation = Factory::make('OsdiObject', 'osdi:donation', $system);
    if (!is_null($id)) {
      $donation->setId($id);
    }
    return $donation;
  }
}

