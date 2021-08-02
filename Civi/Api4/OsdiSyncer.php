<?php

namespace Civi\Api4;

class OsdiSyncer extends Generic\AbstractEntity {

  public static function getFields() {
    // TODO: Implement getFields() method.
  }

  public static function permissions() {
    return [
      'meta' => ['access CiviCRM'],
      'default' => ['administer CiviCRM'],
    ];
  }

}
