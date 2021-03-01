<?php
use CRM_Osdi_ExtensionUtil as E;

class CRM_Osdi_BAO_OsdiMatch extends CRM_Osdi_DAO_OsdiMatch {

  /**
   * Create a new OsdiMatch based on array-data
   *
   * @param array $params key-value pairs
   * @return CRM_Osdi_DAO_OsdiMatch|NULL
   *
  public static function create($params) {
    $className = 'CRM_Osdi_DAO_OsdiMatch';
    $entityName = 'OsdiMatch';
    $hook = empty($params['id']) ? 'create' : 'edit';

    CRM_Utils_Hook::pre($hook, $entityName, CRM_Utils_Array::value('id', $params), $params);
    $instance = new $className();
    $instance->copyValues($params);
    $instance->save();
    CRM_Utils_Hook::post($hook, $entityName, $instance->id, $instance);

    return $instance;
  } */



}
