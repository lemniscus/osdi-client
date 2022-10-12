<?php
namespace Civi\Api4;

use Civi\Api4\Generic\DAOSaveAction;

/**
 * OsdiDeletion entity.
 *
 * Provided by the OSDI Client extension.
 *
 * @package Civi\Api4
 */
class OsdiDeletion extends Generic\DAOEntity {

  public static function save($checkPermissions = TRUE) {
    return (new DAOSaveAction(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions)
      ->setMatch(['sync_profile_id', 'remote_object_id']);
  }

}
