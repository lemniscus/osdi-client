<?php

namespace Civi\Osdi;

class Director {

  public static function acquireLock(string $processName): bool {
    if (static::isBlockedByOtherProcess($processName)) {
      return FALSE;
    }
    \Civi::settings()->add([
      'osdiClient.syncJobProcessId' => getmypid(),
      'osdiClient.syncJobName' => $processName,
      'osdiClient.syncJobStartTime' => date('Y-m-d H:i:s'),
      'osdiClient.syncJobEndTime' => NULL,
    ]);
    return TRUE;
  }

  /**
   * Check whether an exclusive sync process is running.
   *
   * @param string $processName the unique name of a process that needs
   *   exclusive access to Civi & Action Network for syncing
   *
   * @return bool whether another exclusive process is already running
   */
  protected static function isBlockedByOtherProcess(string $processName): bool {
    Logger::logDebug("$processName requested");

    if ($lastJobPid = self::isLastProcessStillRunning()) {
      Logger::logDebug("Sync process ID $lastJobPid is still running; quitting new process");
      return TRUE;
    }
    Logger::logDebug("$processName process ID is " . getmypid());

    if (is_null(\Civi::settings()->get('osdiClient.syncJobEndTime'))) {
      Logger::logDebug('Last sync job did not finish successfully');
    }

    return FALSE;
  }

  protected static function isLastProcessStillRunning(): int {
    $lastJobPid = \Civi::settings()->get('osdiClient.syncJobProcessId');
    if ($lastJobPid && posix_getsid($lastJobPid) !== FALSE) {
      return $lastJobPid;
    }
    return FALSE;
  }

  public static function releaseLock(): void {
    \Civi::settings()->add([
      'osdiClient.syncJobProcessId' => NULL,
      'osdiClient.syncJobEndTime' => date('Y-m-d H:i:s'),
    ]);
  }

}
