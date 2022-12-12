<?php

class CRM_OSDI_BAO_Flag extends CRM_OSDI_DAO_Flag {

  public static function statusPseudoConstant(): array {
    return [
      \Civi\Api4\OsdiFlag::STATUS_ERROR => ts('Error'),
      \Civi\Api4\OsdiFlag::STATUS_RESOLVED => ts('Resolved'),
    ];
  }

}
