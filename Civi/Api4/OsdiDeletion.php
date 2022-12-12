<?php
namespace Civi\Api4;

/**
 * OsdiDeletion entity.
 *
 * Provided by the OSDI Client extension.
 *
 * @package Civi\Api4
 */
class OsdiDeletion extends Generic\DAOEntity {

  public static function save($checkPermissions = TRUE) {
    return (new Patch\DAOSaveAction(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

}
