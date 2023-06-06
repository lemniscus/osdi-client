<?php

namespace Civi\Osdi;

use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\AbstractDumper;
use Symfony\Component\VarDumper\Dumper\CliDumper;

class Logger {

  public static function logError(?string $message, $context = NULL) {
    $priority = self::mapPriority(\Psr\Log\LogLevel::ERROR);

    if (!empty($context)) {
      if (is_array($context) && isset($context['exception'])) {
        $context['exception'] = \CRM_Core_Error::formatTextException($context['exception']);
      }

      // https://symfony.com/doc/current/components/var_dumper.html

      $casters = [
        RemoteObjectInterface::class => function ($object, &$array, $stub, $isNested, $filter) {
          $array = $object->getArrayForCreate();
          return $array;
        },
        ActionNetwork\Object\Person::class => function ($object, &$array, $stub, $isNested, $filter) {
          $array = $object->getArrayForCreate()['person'] ?? [];
          return $array;
        },
        LocalObjectInterface::class => function ($object, &$array, $stub, $isNested, $filter) {
          $array = $object->getAll();
          return $array;
        },
        \Jsor\HalClient\Exception\ExceptionInterface::class => function (\Jsor\HalClient\Exception\ExceptionInterface $object, &$array, $stub, $isNested, $filter) {
          $array = [
            'code' => $object->getCode(),
            'message' => $object->getMessage(),
            'uri' => $object->getRequest()->getUri(),
          ];
          return $array;
        },
      ];

      $cloner = new VarCloner($casters);
      $dumper = new CliDumper(NULL, NULL, AbstractDumper::DUMP_LIGHT_ARRAY);
      $output = $dumper->dump($cloner->cloneVar($context), TRUE);
      $message = "$message\n$output";
    }
    \CRM_Core_Error::debug_log_message($message, FALSE, 'osdi', $priority);
  }

  public static function logDebug(?string $message) {
    $priority = self::mapPriority(\Psr\Log\LogLevel::DEBUG);
    \CRM_Core_Error::debug_log_message($message, FALSE, 'osdi', $priority);
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