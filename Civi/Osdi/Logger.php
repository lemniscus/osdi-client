<?php

namespace Civi\Osdi;

use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\AbstractDumper;
use Symfony\Component\VarDumper\Dumper\CliDumper;

class Logger extends \CRM_Core_Error_Log {

  public function log($level, $message, array $context = []): void {
    if (!empty($context)) {
      $message = self::addContextToMessage($context, $message);
    }

    $pearPriority = $this->map[$level];

    $file_log = \CRM_Core_Error::createDebugLogger('osdi');
    $file_log->log("$message\n", $pearPriority);
    $file_log->close();
  }
  protected static function addContextToMessage(mixed $context, ?string $message): ?string {
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
          $array = $object->getAllWithoutLoading();
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
    return $message;
  }

  public static function logError(?string $message, $context = NULL) {
    self::getSingleton()->error($message);
  }

  public static function logDebug(?string $message, $context = NULL) {
    self::getSingleton()->debug($message);
  }

  private static function getSingleton(): mixed {
    $singleton =& \Civi::$statics[static::class]['singleton'];
    if (empty($singleton)) {
      $singleton = \Civi::log('osdi');
    }
    return $singleton;
  }

}
