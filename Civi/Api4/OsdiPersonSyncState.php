<?php

namespace Civi\Api4;

use Civi\Api4\Action\DAOSaveActionPatched;

/**
 * OsdiPersonSyncState entity.
 *
 * Provided by the OSDI Client extension.
 *
 * @package Civi\Api4
 * @dao CRM_OSDI_DAO_PersonSyncState
 */
class OsdiPersonSyncState extends Generic\DAOEntity {

  const syncOriginLocal = 0;
  const syncOriginRemote = 1;

  /**
   * CAN BE REMOVED ONCE https://github.com/civicrm/civicrm-core/pull/24971
   * IS RELEASED -- UPDATE THE REQUIRED CIVI VERSION OF THIS EXTENSION
   */
  public static function save($checkPermissions = TRUE) {
    return (new DAOSaveActionPatched(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

}
