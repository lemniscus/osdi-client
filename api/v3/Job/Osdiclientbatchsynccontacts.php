<?php

use Civi\OsdiClient;

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
function civicrm_api3_job_Osdiclientbatchsynccontacts($params) {
  $container = OsdiClient::containerWithDefaultSyncProfile();
  $system = $container->getSingle('RemoteSystem', 'ActionNetwork');

  $singleSyncer = $container->getSingle('SingleSyncer', 'Person', $system);
  $batchSyncer = $container->getSingle('BatchSyncer', 'Person', $singleSyncer);

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
    'Osdiclientbatchsynccontacts');
}
