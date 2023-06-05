<?php

namespace Civi\Osdi;

use Civi\Api4\Generic\DAOGetAction;
use Civi\Api4\OsdiDonationSyncState;
use Civi\Api4\OsdiPersonSyncState;
use Civi\Osdi\Exception\InvalidArgumentException;

class DonationSyncState {

  private int $id;

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

}
