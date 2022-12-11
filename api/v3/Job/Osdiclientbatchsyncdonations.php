<?php

use Civi\Osdi\Factory;

/**
 * Job.Osdiclientbatchsynccontacts API
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
function civicrm_api3_job_Osdiclientbatchsyncdonations($params) {
  if (empty($params['api_token'])) {
    throw new Exception('Cannot sync with Action Network without an API token');
  }

  $system = Factory::initializeRemoteSystem($params['api_token']);

  $singleSyncer = Factory::singleton('SingleSyncer', 'Donation', $system);
  $batchSyncer = Factory::singleton('BatchSyncer', 'Donation', $singleSyncer);

  try {
    $countFromRemote = $batchSyncer->batchSyncFromRemote();
    $countFromLocal = $batchSyncer->batchSyncFromLocal();
  }
  catch (Throwable $e) {
    return civicrm_api3_create_error(
      $e->getMessage(),
      ['exception' => \CRM_Core_Error::formatTextException($e)],
    );
  }

  return civicrm_api3_create_success(
    "AN->Civi: $countFromRemote, Civi->AN: $countFromLocal",
    $params,
    'Job',
    'civicrm_api3_job_Osdiclientbatchsyncdonations');
}

