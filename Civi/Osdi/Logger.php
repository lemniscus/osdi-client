<?php

namespace Civi\Osdi;

class Logger {
    public static $previousTime = [];
    public static $tasks = [];

  public static function logError(?string $message, $context = NULL) {
    $priority = self::mapPriority(\Psr\Log\LogLevel::ERROR);

    if (!empty($context)) {
      if (is_array($context) && isset($context['exception'])) {
        $context['exception'] = \CRM_Core_Error::formatTextException($context['exception']);
      }
      $message .= "\n" . print_r($context, 1);
    }
    \CRM_Core_Error::debug_log_message($message, FALSE, 'osdi', $priority);
  }

  public static function logDebug(?string $message) {
    $priority = self::mapPriority(\Psr\Log\LogLevel::DEBUG);
    \CRM_Core_Error::debug_log_message($message, FALSE, 'osdi', $priority);
  }

  public static function startTask(?string $message)  {
    static::$previousTime[] = microtime(TRUE);
    static::$previousTime[] = microtime(TRUE);
    static::$tasks[] = $message;
    $now = (new \DateTime())->format('H:i:s.v');
    static::logDebug($now . str_repeat(' ', count(static::$tasks)) .  " $message");
  }

  public static function progressTask(?string $message)  {
    $previous = array_pop(static::$previousTime);
    $t = microtime(TRUE);
    static::$previousTime[] = $t;
    $took = round($t - $previous, 2);

    $now = (new \DateTime())->format('H:i:s.v');
    static::logDebug($now . str_repeat(' ', count(static::$tasks)) .  "(+{$took}s) $message");
  }
  public static function endTask(?string $message='')  {
    $t = microtime(TRUE);
    $previous = array_pop(static::$previousTime);
    $previous = array_pop(static::$previousTime);
    static::$previousTime[] = $t;
    $took = round($t - $previous, 2);

    $now = (new \DateTime())->format('H:i:s.v');
    static::logDebug($now . str_repeat(' ', count(static::$tasks)) .  "(+{$took}s) end of task. $message");
  }

  private static function mapPriority(string $psrLogLevel) {
    static $levelMap;

    if (!isset($levelMap)) {
      $levelMap = \Civi::log()->getMap();
    }

    $priority = $levelMap[$psrLogLevel];
    return $priority;
  }

}
