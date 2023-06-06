<?php

namespace Civi\Osdi;

use Civi\Api4\Generic\DAOGetAction;
use Civi\Api4\OsdiDonationSyncState;
use Civi\Api4\OsdiPersonSyncState;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\OsdiClient;

class DonationSyncState implements SyncStateInterface {

  protected array $data = [];
  private ?int $id = NULL;
  private ?LocalObjectInterface $localObject = NULL;
  private ?RemoteObjectInterface $remoteObject = NULL;

  public function __construct(int $id = NULL) {
    $this->id = $id;
  }

  public function __debugInfo(): array {
    return $this->data;
  }

  public static function fromArray(array $record): self {
    $id = $record['id'] ?? NULL;
    return (new static($id))->setData($record);
  }

  public static function getDbTable() {
    static $table_name = NULL;
    $table_name = $table_name ?? OsdiDonationSyncState::getInfo()['table_name'];
    return $table_name;
  }

  public function getId(): int {
    return $this->id;
  }

  public function setId(int $id): self {
    $this->id = $id;
    return $this;
  }

  public function getLocalObject(): ?LocalObjectInterface {
    if (empty($this->localObject)) {
      $id = $this->getLocalObjectId();
      if (!empty($id)) {
        $this->localObject = OsdiClient::container()
          ->make('LocalObject', 'Donation', $id);
      }
    }
    return $this->localObject;
  }

  public function getLocalObjectId(): ?int {
    return $this->getData()['contribution_id'] ?? NULL;
  }

  public function getRemoteObject(): ?RemoteObjectInterface {
    if (empty($this->remoteObject)) {
      $id = $this->getRemoteObjectId();
      if (!empty($id)) {
        $this->remoteObject = OsdiClient::container()
          ->make('OsdiObject', 'osdi:donations');
        $this->remoteObject->setId($id);
      }
    }
    return $this->remoteObject;
  }

  public function getRemoteObjectId() {
    return $this->getData()['remote_donation_id'] ?? NULL;
  }

  protected function getData(): array {
    if (empty($this->data) && !empty($this->id)) {
      $this->data = OsdiDonationSyncState::get(FALSE)
        ->addWhere('id', '=', $this->id)
        ->execute()->first();
    }
    return $this->data;
  }

  public function setData(array $data): static {
    $this->data = $data;
    return $this;
  }

}
