<?php

namespace Civi\Osdi;

use Symfony\Component\EventDispatcher\Event;

class Queue {

  public static function getQueue(bool $reset = FALSE): \CRM_Queue_Queue_Sql {
    $queue = \Civi::$statics['osdiClient.queue'] ?? NULL;
    if (is_null($queue) || $reset) {
      $queue = \Civi::queue('osdi_client', [
        'type'  => 'Sql',
        'runner' => 'task',
        'retry_interval' => 3,
        'retry_limit' => 0,
        // if run by CRM_Queue_TaskRunner, "delete" actually means "retry task, then delete it"
        // https://github.com/civicrm/civicrm-core/blob/5.53/CRM/Queue/TaskRunner.php#L59
        'error' => 'delete',
        'reset' => $reset,
      ]);
      \Civi::$statics['osdiClient.queue'] = $queue;
    }
    return $queue;
  }

  public static function runQueue(Event $e) {
    //CRM_Queue_Queue $queue, array $items, array &$outcomes
    foreach ($items as $itemPos => $item) {
      Logger::logDebug('Running queued task: ' . $item->data->title);
      $outcome = (new \CRM_Queue_TaskRunner())->run($queue, $item);
      $outcomes[$itemPos] = $outcome;
      Logger::logDebug("Queued task result: $outcome");
    }
  }

}
