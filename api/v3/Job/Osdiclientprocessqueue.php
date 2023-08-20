<?php

use Civi\Osdi\Director;
use Civi\OsdiClient;
use CRM_OSDI_ExtensionUtil as E;

/**
 * Job.Osdiclientprocessqueue API specification
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call, in the
 *   format returned by the api v3 getFields action
 *
 */
function _civicrm_api3_job_Osdiclientprocessqueue_spec(&$spec) {
  $spec['sync_profile_id'] = [
    'title' => E::ts('Sync Profile ID'),
    'type' => CRM_Utils_Type::T_INT,
    'api.required' => TRUE,
  ];
}

/**
 * Job.Osdiclientprocessqueue API
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
function civicrm_api3_job_Osdiclientprocessqueue($params) {
  if (!Director::acquireLock('Run queued tasks')) {
    return NULL;
  }

  try {
    OsdiClient::container($params['sync_profile_id']);

    $queue = \Civi\Osdi\Queue::getQueue();
    $queueName = $queue->getName();
    $runItemsAction = \Civi\Api4\Queue::runItems(FALSE)->setQueue($queueName);
    $statusSummary = [];

    while ($queue->numberOfItems() > 0) {
      try {
        $result = $runItemsAction->execute()->single();
        $outcome = $result['outcome'] ?? '-';
        $itemId = $result['item']['id'];
        \Civi\Osdi\Logger::logDebug("Ran queue item id $itemId: $outcome");
      }
      catch (Throwable $e) {
        $result = [];
        $outcome = '-';
        throw $e;
      }

      $statusSummary[$outcome] = $statusSummary[$outcome] ?? 0;
      $statusSummary[$outcome]++;
    }
  }
  finally {
    Director::releaseLock();
  }

  \Civi\Osdi\Logger::logDebug('Queued task run finished');

  return civicrm_api3_create_success($statusSummary, $params, 'Job', 'Osdiclientprocessqueue');
}
