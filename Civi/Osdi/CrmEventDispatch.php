<?php

namespace Civi\Osdi;

class CrmEventDispatch {

  protected static function getResponder($objectName, $hookName) {
    if (Factory::canMake('CrmEventResponder', $objectName)) {
      $responder = Factory::make('CrmEventResponder', $objectName);
      if (method_exists($responder, $hookName)) {
        return $responder;
      }
    }

    return FALSE;
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
    //  // not used anywhere
    //  if ('eidRefs' === $type) {
    //    \Civi::dispatcher()->dispatch('civi.osdi.contactmerge.tablemap',
    //      \Civi\Core\Event\GenericHookEvent::create(['tableMap' => &$data]));
    //  }

    if ('sqls' !== $type) {
      return;
    }

    $responder = static::getResponder('Contact', 'merge');
    if ($responder) {
      // mainId is the one being kept; otherId belongs to the contact being deleted
      $responder->merge($mainId, $otherId);
    }
  }

  public static function pre($op, $objectName, $id, &$params): void {
    $responder = static::getResponder($objectName, 'pre');
    if ($responder) {
      $responder->pre($op, $objectName, $id, $params);
    }
  }

  public static function postCommit($op, $objectName, $objectId, &$objectRef): void {
    $responder = static::getResponder($objectName, 'postCommit');
    if ($responder) {
      $responder->postCommit($op, $objectName, $objectId, $objectRef);
    }
  }

}
