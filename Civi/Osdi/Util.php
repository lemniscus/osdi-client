<?php

namespace Civi\Osdi;

use Civi\Osdi\Exception\InvalidArgumentException;

class Util {

  public static function validateAndNormalizeApiOriginParam(
    array $params,
    array $acceptable,
    array $default
  ): array {
    $origins = explode(',', $params['origin'] ?? '');
    $origins = array_map('trim', $origins);
    if (count($origins) === 0) {
      $origins = $default;
    }
    elseif (count($origins) > 2) {
      throw new InvalidArgumentException(
        'Too many origins passed to API action.'
        . ' There should be no more than two.');
    }
    else {
      foreach ($origins as $origin) {
        if (!in_array($origin, $acceptable)) {
          throw new InvalidArgumentException(
            'Invalid origin passed to API action: %s',
            $origin
          );
        }
      }
    }
    return $origins;
  }

}
