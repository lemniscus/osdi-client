<?php

use CRM_OSDI_ExtensionUtil as E;

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
  if (empty($params['api_token'])) {
    throw new Exception('Cannot sync with Action Network without an API token');
  }

  \Civi\Osdi\Factory::initializeRemoteSystem($params['api_token']);

  $queue = \Civi\Osdi\Queue::getQueue();
  $queueName = $queue->getName();
  $runItemsAction = \Civi\Api4\Queue::runItems(FALSE)->setQueue($queueName);
  $statusSummary = [];

  while ($queue->numberOfItems() > 0) {
    try {
      $result = $runItemsAction->execute()->single();
    }
    catch (Throwable $e) {
      throw $e;
    }
    $outcome = $result['outcome'];
    $statusSummary[$outcome] = $statusSummary[$outcome] ?? 0;
    $statusSummary[$outcome]++;
  }

  return civicrm_api3_create_success($statusSummary, $params, 'Job', 'Osdiclientprocessqueue');
}
