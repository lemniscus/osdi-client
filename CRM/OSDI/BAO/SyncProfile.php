<?php

class CRM_OSDI_BAO_SyncProfile extends CRM_OSDI_DAO_SyncProfile {

  public static function writeRecords(array $records): array {
    $defaultClaimed = FALSE;
    foreach ($records as $record) {
      if (empty($record['is_default'])) {
        continue;
      }
      if ($defaultClaimed) {
        $record['is_default'] = FALSE;
      }
      else {
        $defaultClaimed = TRUE;
        $query = 'UPDATE ' . self::getTableName() . ' SET is_default = 0';
        CRM_Core_DAO::executeQuery($query);
      }
    }
    return parent::writeRecords($records);
  }

  public static function getRemoteSystems() {

  }

  public static function getMatchers() {

  }

  public static function getMappers() {

  }

}
