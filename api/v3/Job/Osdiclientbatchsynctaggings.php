<?php
use \Civi\Osdi\Factory;
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
  $spec['api_key']['api.required'] = 1;
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
  if (empty($params['api_token'])) {
    throw new Exception('Cannot sync with Action Network without an API token');
  }

  $system = Factory::initializeRemoteSystem($params['api_token']);

  $singleSyncer = Factory::singleton('SingleSyncer', 'Tagging', $system);
  $batchSyncer = Factory::singleton('BatchSyncer', 'Tagging', $singleSyncer);

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
