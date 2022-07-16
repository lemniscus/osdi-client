<?php

namespace Civi\Api4;

class OsdiSyncer extends Generic\AbstractEntity {

  public static function getFields($checkPermissions = TRUE) {
    $action = new Generic\BasicGetFieldsAction(
      'Contact',
      __FUNCTION__,
      function () {
        return [];
      }
    );
    return $action->setCheckPermissions($checkPermissions);
  }

  public static function permissions() {
    return [
      'meta' => ['access CiviCRM'],
      'default' => ['administer CiviCRM'],
    ];
  }

}
