<?php
use CRM_OSDI_ExtensionUtil as E;

class CRM_OSDI_BAO_DonationSyncState extends CRM_OSDI_DAO_DonationSyncState {

  /**
   * Create a new DonationSyncState based on array-data
   *
   * @param array $params key-value pairs
   * @return CRM_OSDI_DAO_DonationSyncState|NULL
   *
  public static function create($params) {
    $className = 'CRM_OSDI_DAO_DonationSyncState';
    $entityName = 'DonationSyncState';
    $hook = empty($params['id']) ? 'create' : 'edit';

    CRM_Utils_Hook::pre($hook, $entityName, CRM_Utils_Array::value('id', $params), $params);
    $instance = new $className();
    $instance->copyValues($params);
    $instance->save();
    CRM_Utils_Hook::post($hook, $entityName, $instance->id, $instance);

    return $instance;
  } */

}
