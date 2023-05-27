<?php

namespace Civi\Osdi;

use Civi\Osdi\Exception\InvalidArgumentException;

class Util {

  public static function assertClass($var, string $correctClass) {
    if (!is_a($var, $correctClass)) {
      $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
      $callingFunction = $backtrace[1]['function'];
      $callingClass = $backtrace[1]['class'];
      throw new InvalidArgumentException('Argument to %s must be of type %s',
        "$callingClass::$callingFunction", $correctClass);
    }
  }

  public static function validateAndNormalizeApiOriginParam(array $params): array {
    $origins = explode(',', $params['origin'] ?? '');
    $origins = array_map('trim', $origins);
    if (count($origins) === 0) {
      $origins = ['remote', 'local'];
    }
    elseif (count($origins) > 2) {
      throw new InvalidArgumentException(
        'Too many origins passed to API action.'
        . ' There should be no more than two.');
    }
    else {
      foreach ($origins as $origin) {
        if (!in_array($origin, ['local', 'remote'])) {
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
