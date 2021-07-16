<?php
use CRM_OSDI_ExtensionUtil as E;

class CRM_OSDI_BAO_OSDIMatch extends CRM_OSDI_DAO_OSDIMatch {

  const ORIGIN_LOCAL = 0;
  const ORIGIN_REMOTE = 1;

  public static function syncOriginPseudoConstant(): array {
    return [
      self::ORIGIN_LOCAL => 'local',
      self::ORIGIN_REMOTE => 'remote'
    ];
  }

}
