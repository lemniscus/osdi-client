<?php

namespace Civi\Osdi;

use CRM_Core_DAO;

trait NonTransactionalTestTrait {

  protected static string $cacheMaxTime;

  protected static array $objectsToDelete = [];

  protected static array $tableMaxIds = [];

  public static function nonTransactionalSetUpBeforeClass(): void {
    $cleanupTables = [
      'civicrm_contact',
      'civicrm_osdi_deletion',
      'civicrm_osdi_flag',
      'civicrm_osdi_person_sync_state',
      'civicrm_osdi_sync_profile',
      'civicrm_queue',
      'civicrm_queue_item',
      'civicrm_tag',
    ];
    foreach ($cleanupTables as $cleanupTable) {
      $max = CRM_Core_DAO::singleValueQuery("SELECT MAX(id) FROM $cleanupTable");
      self::$tableMaxIds[$cleanupTable] = $max;
    };
    self::$cacheMaxTime = CRM_Core_DAO::singleValueQuery("SELECT MAX(created_date) FROM civicrm_cache");
    // make sure that new cache entries have a different timestamp
    usleep(2000);
  }

  protected function nonTransactionaltearDown(): void {
    foreach (self::$tableMaxIds as $table => $maxId) {
      $where = $maxId ? "WHERE id > $maxId" : "";
      CRM_Core_DAO::singleValueQuery("DELETE FROM $table $where");
    }

    CRM_Core_DAO::singleValueQuery('DELETE FROM civicrm_cache WHERE created_date > %0',
      [[self::$cacheMaxTime, 'String']]);

    \Civi\Osdi\Queue::getQueue(TRUE);

    foreach (self::$objectsToDelete as $object) {
      try {
        if ('Contact' === $object::getCiviEntityName()) {
          \CRM_Core_DAO::singleValueQuery('DELETE FROM civicrm_contact '
            . 'WHERE id = ' . $object->getId());
          continue;
        }
        $object->delete();
      }
      catch (\Throwable $e) {
        $class = get_class($object);
        print "Could not delete $class\n";
      }
    }

  }

}
