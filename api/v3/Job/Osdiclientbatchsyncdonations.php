<?php

use Civi\OsdiClient;
use CRM_OSDI_ExtensionUtil as E;

/**
 * Job.Osdiclientbatchsyncdonations API specification
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call, in the
 *   format returned by the api v3 getFields action
 *
 */
function _civicrm_api3_job_Osdiclientbatchsyncdonations_spec(&$spec) {
  $spec['sync_profile_id'] = [
    'title' => E::ts('Sync Profile ID'),
    'type' => CRM_Utils_Type::T_INT,
    'api.required' => TRUE,
  ];
  $spec['origin'] = [
    'title' => E::ts('Origin'),
    'description' => E::ts('Which system(s) to sync from, in which order. '
      . 'Acceptable values: '
      . '"local", "remote", "local,remote" or "remote,local". '
      . 'Default: "remote,local".'),
    'type' => CRM_Utils_Type::T_STRING,
    'api.required' => FALSE,
  ];
}

/**
 * Job.Osdiclientbatchsyncdonations API
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
  $origins = \Civi\Osdi\Util::validateAndNormalizeApiOriginParam($params);
  $container = OsdiClient::container($params['sync_profile_id']);
  $batchSyncer = $container->getSingle('BatchSyncer', 'Donation');
  $message = [];
  try {
    foreach ($origins as $origin) {
      if ('remote' === $origin) {
        $countFromRemote = $batchSyncer->batchSyncFromRemote();
        $message[] = "AN->Civi: $countFromRemote";
      }
      elseif ('local' === $origin) {
        $countFromLocal = $batchSyncer->batchSyncFromLocal();
        $message[] = "Civi->AN: $countFromLocal";
      }
    }
  }
  catch (Throwable $e) {
    return civicrm_api3_create_error(
      $e->getMessage(),
      ['exception' => \CRM_Core_Error::formatTextException($e)],
    );
  }

  return civicrm_api3_create_success(
    implode(', ', $message),
    $params,
    'Job',
    'civicrm_api3_job_Osdiclientbatchsyncdonations');
}

