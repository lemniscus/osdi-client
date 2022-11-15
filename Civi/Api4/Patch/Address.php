<?php

namespace Civi\Api4\Patch;

use Civi\Api4\Action\DAOSaveActionPatched;

/**
 * CAN BE REMOVED ONCE https://github.com/civicrm/civicrm-core/pull/24971
 * IS RELEASED -- UPDATE THE REQUIRED CIVI VERSION OF THIS EXTENSION
 */
class Address extends \Civi\Api4\Address {

  public static function save($checkPermissions = TRUE) {
    return (new DAOSaveActionPatched(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

}
