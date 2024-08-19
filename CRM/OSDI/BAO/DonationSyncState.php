<?php

class CRM_OSDI_BAO_DonationSyncState extends CRM_OSDI_DAO_DonationSyncState {

  public static function getSources() {
    return [
      'local' => 'local',
      'remote' => 'remote',
    ];
  }

}
