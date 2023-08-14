<?php

namespace {

  use function Civi\Osdi\Api3CleanUpLogTable\apiSpec;
  use function Civi\Osdi\Api3CleanUpLogTable\run;

  /**
   * API parameter metadata for documentation and validation.
   *
   * @param array $spec description of fields supported by this API call, in the
   *   format returned by the api v3 getFields action
   */
  function _civicrm_api3_job_Osdiclientcleanuplogtable_spec(array &$spec): void {
    apiSpec($spec);
  }

  /**
   * Job.Osdiclientcleanuplogtable API
   * @return array API result descriptor
   */
  function civicrm_api3_job_Osdiclientcleanuplogtable($params): array {
    return run($params);
  }

}

namespace Civi\Osdi\Api3CleanUpLogTable {

  use Civi\Api4\OsdiLog;
  use Civi\Osdi\Exception\InvalidArgumentException;
  use CRM_OSDI_ExtensionUtil as E;

  function apiSpec(&$spec) {
    $spec['count'] = [
      'title' => E::ts('Row Count Limit'),
      'description' => 'Default: 24000. '
        . 'Set to "none" for no limit. '
        . 'Rows will be deleted if Age Limit OR Size Limit is exceeded.',
      'type' => \CRM_Utils_Type::T_STRING,
      'api.required' => FALSE,
      'api.default' => '24000',
    ];
    $spec['age'] = [
      'title' => E::ts('Age Limit'),
      'description' => 'For example, "none", "30 minutes", "2 hours" or "1 day". '
      . 'Default: 1 day. Rows will be deleted if Age Limit OR Size Limit is exceeded.',
      'type' => \CRM_Utils_Type::T_STRING,
      'api.required' => FALSE,
      'api.default' => '1 day',
    ];
  }

  /**
   * Clean up the log table. Note, we investigated using a size limit (in bytes)
   * but it's extremely expensive (in terms of memory, disk i/o, time) to
   * figure out precisely how big an InnoDB table is or how big a swath of rows
   * is. The best thing we can suggest is to use a conservative row count limit,
   * based on the rough estimate of 4 kilobytes disk space per row.
   */
  function run($params): array {
    [$countLimit, $ageLimit] = validateParams($params);
    $logTableName = \CRM_OSDI_DAO_Log::getTableName();

    $deletedOldRowCount = deleteOldRows($ageLimit, $logTableName);
    if ($deletedOldRowCount) {
      $messages[] = "deleted $deletedOldRowCount entries older than $ageLimit";
    }

    $deletedExcessRowCount = deleteExcessRows($countLimit, $logTableName);
    if ($deletedExcessRowCount) {
      $messages[] = "deleted $deletedExcessRowCount entries to bring count down to $countLimit";
    }

    $messages = $messages ?? ['no entries met the criteria for deletion'];
    $message = implode(', and ', $messages);

    $oldestLog = OsdiLog::get(FALSE)
      ->setLimit(1)
      ->addOrderBy('id', 'ASC')
      ->setSelect(['id', 'created_date'])
      ->execute()->first();

    if ($oldestLog) {
      $id = $oldestLog['id'];
      $date = $oldestLog['created_date'];
      $message .= "; oldest entry is currently id $id, dated $date.";
    }
    else {
      $message .="; there are currently no entries in the table.";
    }

    return makeSuccessResult($message, $params);
  }

  function deleteOldRows($ageLimit, string $logTableName): ?int {
    if ('none' === $ageLimit) {
      return 0;
    }
    $dateCutoff = date('Y-m-d H:i:s', strtotime("- $ageLimit"));
    // could use Civi API here but this is efficient
    $query = "DELETE FROM `$logTableName` WHERE created_date < '$dateCutoff'";
    return \CRM_Core_DAO::executeQuery($query)->affectedRows();
  }

  function deleteExcessRows($countLimit, string $logTableName): ?int {
    if ('none' === $countLimit) {
      return 0;
    }
    $rowCount = OsdiLog::get()->selectRowCount()->execute()->rowCount ?? 0;
    $excessRows = $rowCount - $countLimit;
    if ($excessRows < 1) {
      return 0;
    }
    // could use Civi API here but this is efficient
    $query = "DELETE FROM `$logTableName` ORDER BY id ASC LIMIT $excessRows";
    return \CRM_Core_DAO::executeQuery($query)->affectedRows();
  }

  function makeSuccessResult(string $statusSummary, $params): array {
    return civicrm_api3_create_success($statusSummary, $params, 'Job', 'Osdiclientcleanuplogtable');
  }

  function validateParams($params): array {
    $countLimit = strtolower($params['count'] ?? '');
    if (!is_numeric($countLimit) && ($countLimit !== 'none')) {
      throw new InvalidArgumentException('"%s" is not a valid option for row count limit', $countLimit);
    }

    $ageLimit = strtolower($params['age']) ?? '';
    if ($ageLimit !== 'none') {
      $ageLimitIsValid = preg_match(
        '/^\s*\d+\s*(second|minute|hour|day|week|fortnight|month|year)s?\s*/i',
        $ageLimit);
      if (!$ageLimitIsValid) {
        throw new InvalidArgumentException('"%s" is not a valid option for age limit', $ageLimit);
      }
    }

    if (('none' === $countLimit) && ('none' === $ageLimit)) {
      throw new InvalidArgumentException('Either an age limit or size limit must be specified');
    }

    return array($countLimit, $ageLimit);
  }

}
