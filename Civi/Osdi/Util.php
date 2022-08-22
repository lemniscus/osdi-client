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

}
