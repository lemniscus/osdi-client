<?php

namespace Civi\Osdi;

class CrmEventDispatch {

  protected static function getResponder($objectName, $hookName) {
    try {
      $canMakeResponder = \Civi\OsdiClient::container()
        ->canMake('CrmEventResponder', $objectName);
    }
    catch (\Throwable $e) {
      $canMakeResponder = FALSE;
      \Civi::log()->warning('OSDI Client could not respond to event '
        . "$hookName for $objectName due to error: " . $e->getMessage(),
        [$e->getTrace()[0] ?? NULL]
      );
    }
    if ($canMakeResponder) {
      $responder = \Civi\OsdiClient::container()->make('CrmEventResponder', $objectName);
      if (method_exists($responder, $hookName)) {
        return $responder;
      }
    }

    return FALSE;
  }

  public static function alterLocationMergeData(&$blocksDAO, $mainId, $otherId, $migrationInfo) {
    $responder = static::getResponder('Contact', 'alterLocationMergeData');
    if ($responder) {
      // mainId is the one being kept; otherId belongs to the contact being deleted
      $responder->alterLocationMergeData($blocksDAO, $mainId, $otherId, $migrationInfo);
    }
  }

  public static function daoPreDelete($event): void {
    $objectName = \_civicrm_api_get_entity_name_from_dao($event->object);
    $responder = static::getResponder($objectName, 'daoPreDelete');
    if ($responder) {
      $responder->daoPreDelete($event);
    }
  }

  public static function daoPreUpdate($event): void {
    $objectName = \_civicrm_api_get_entity_name_from_dao($event->object);
    $responder = static::getResponder($objectName, 'daoPreUpdate');
    if ($responder) {
      $responder->daoPreUpdate($event);
    }
  }

  public static function merge($type, &$data, $mainId = NULL, $otherId = NULL, $tables = NULL): void {
    $responder = static::getResponder('Contact', 'merge');
    if ($responder) {
      // mainId is the one being kept; otherId belongs to the contact being deleted
      $responder->merge($type, $data, $mainId, $otherId, $tables);
    }
  }

  public static function pre($op, $objectName, $id, &$params): void {
    $responder = static::getResponder($objectName, 'pre');
    if ($responder) {
      $responder->pre($op, $objectName, $id, $params);
    }
  }

  public static function post(string $op, string $objectName, int $objectId, &$objectRef): void {
    $responder = static::getResponder($objectName, 'post');
    if ($responder) {
      $responder->post($op, $objectName, $objectId, $objectRef);
    }
  }

  public static function postCommit($op, $objectName, $objectId, &$objectRef): void {
    $responder = static::getResponder($objectName, 'postCommit');
    if ($responder) {
      $responder->postCommit($op, $objectName, $objectId, $objectRef);
    }
  }

}
