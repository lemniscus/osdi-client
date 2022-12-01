<?php
namespace Civi\Api4;

/**
 * OsdiFlag entity.
 *
 * Provided by the OSDI Client extension.
 *
 * @package Civi\Api4
 */
class OsdiFlag extends Generic\DAOEntity {

  const STATUS_ERROR = 'error';

  const STATUS_RESOLVED = 'resolved';

  public static function statusPseudoConstant(): array {
    return [
      self::STATUS_ERROR => ts('Error'),
      self::STATUS_RESOLVED => ts('Resolved'),
    ];
  }

}
