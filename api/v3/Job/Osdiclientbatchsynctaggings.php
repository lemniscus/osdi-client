<?php
use \Civi\Osdi\Container;
use CRM_OSDI_ExtensionUtil as E;

/**
 * Job.Osdiclientbatchsynctaggings API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_job_Osdiclientbatchsynctaggings_spec(&$spec) {
  //$spec['api_token']['api.required'] = 1;
}

/**
 * Job.Osdiclientbatchsynctaggings API
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor
 *
 * @see civicrm_api3_create_success
 *
 * @throws API_Exception
 */
function civicrm_api3_job_Osdiclientbatchsynctaggings($params) {
  $container = \Civi\OsdiClient::containerWithDefaultSyncProfile();
  $system = $container->getSingle('RemoteSystem', 'ActionNetwork');

  $singleSyncer = $container->getSingle('SingleSyncer', 'Tagging', $system);
  $batchSyncer = $container->getSingle('BatchSyncer', 'Tagging', $singleSyncer);

  try {
    $countFromRemote = $batchSyncer->batchSyncFromRemote();
  }
  catch (Throwable $e) {
    return civicrm_api3_create_error(
      $e->getMessage(),
      ['exception' => \CRM_Core_Error::formatTextException($e)],
    );
  }

  return civicrm_api3_create_success(
    "AN->Civi: $countFromRemote",
    $params,
    'Job',
    'Osdiclientbatchsynctaggings');
}
