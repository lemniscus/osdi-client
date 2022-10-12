<?php

namespace Civi\Osdi;

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

}
