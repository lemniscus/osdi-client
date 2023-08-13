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

  use Civi\Osdi\Exception\InvalidArgumentException;
  use CRM_OSDI_ExtensionUtil as E;

  function apiSpec(&$spec) {
    $spec['size'] = [
      'title' => E::ts('Size Limit (MB)'),
      'description' => 'Default: 64. Set to "none" for no limit. '
      . '. Rows will be deleted if Age Limit OR Size Limit is exceeded.',
      'type' => \CRM_Utils_Type::T_STRING,
      'api.required' => FALSE,
      'api.default' => 64,
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

  function run($params): array {
    [$megabyteLimit, $ageLimit] = validateParams($params);
    $logTableName = \CRM_OSDI_DAO_Log::getTableName();

    $deletedRowCount = deleteOldRows($ageLimit, $logTableName);

    if ($megabyteLimit === 'none') {
      $message = "deleted $deletedRowCount older than $ageLimit";
      return makeSuccessResult($message, $params);
    }

    // At this point, the age limit has been taken care of; we are only
    // concerned with the size limit.

    $byteLimit = $megabyteLimit * 1024 * 104;
    $tableBytes = getTableBytes($logTableName);
    $sizeIsOverLimit = $tableBytes > $byteLimit;

    if (!$sizeIsOverLimit) {
      $message = ($deletedRowCount == 0) ?
        'deletion not triggered' :
        "deleted $deletedRowCount older than $ageLimit";
      return makeSuccessResult($message, $params);
    }

    for ($i = 0; $i < 200; $i++) {
      // We should get below the size limit in well under 200 iterations of this
      // loop, but we cap it at 200 just as a sanity check.

      $excessSizeRatio = ($tableBytes / $byteLimit) - 1;
      $totalRows = getTableRowCount($logTableName);
      $chunkSize = max(1, $totalRows * ($excessSizeRatio / 10));

      $query = "DELETE FROM `$logTableName` ORDER BY id DESC LIMIT $chunkSize";
      $deletedRowCount += \CRM_Core_DAO::executeQuery($query)->affectedRows();

      $tableBytes = getTableBytes($logTableName);
      if ($tableBytes <= $byteLimit) {
        $message = "deleted $deletedRowCount to bring table size below $megabyteLimit";
        return makeSuccessResult($message, $params);
      }
    }

    $message = "deleted $deletedRowCount, but $logTableName is still $tableBytes "
      . "bytes, which is greater than the specified limit of $byteLimit bytes "
      . "($megabyteLimit MB)";

    return makeSuccessResult($message, $params);
  }

  function deleteOldRows($ageLimit, string $logTableName): ?int {
    if ('none' === $ageLimit) {
      return 0;
    }
    $dateCutoff = date('Y-m-d H:i:s', strtotime("- $ageLimit"));
    $query = "DELETE FROM `$logTableName` WHERE created_date < '$dateCutoff'";
    return \CRM_Core_DAO::executeQuery($query)->affectedRows();
  }

  function makeSuccessResult(string $statusSummary, $params): array {
    return civicrm_api3_create_success($statusSummary, $params, 'Job', 'Osdiclientcleanuplogtable');
  }

  function validateParams($params): array {
    $megabyteLimit = strtolower($params['size'] ?? '');
    if (!is_numeric($megabyteLimit) && ($megabyteLimit !== 'none')) {
      throw new InvalidArgumentException('"%s" is not a valid option for size limit', $megabyteLimit);
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

    if (('none' === $megabyteLimit) && ('none' === $ageLimit)) {
      throw new InvalidArgumentException('Either an age limit or size limit must be specified');
    }

    return array($megabyteLimit, $ageLimit);
  }

  function getTableBytes(string $logTableName): ?string {
    static $dbName = NULL;
    $dbName = $dbName ?? \CRM_Core_DAO::getDatabaseName();
    $query = "SELECT data_length + index_length AS table_size "
      . "FROM information_schema.TABLES "
      . "WHERE table_schema = '$dbName' AND table_name = '$logTableName'";
    $tableSize = \CRM_Core_DAO::singleValueQuery($query);
    if (!is_numeric($tableSize)) {
      throw new \Exception('Could not get civicrm_osdi_log table size');
    }
    return $tableSize;
  }

  function getTableRowCount(string $logTableName): ?string {
    $query = "SELECT COUNT(*) FROM `$logTableName`";
    $count = \CRM_Core_DAO::singleValueQuery($query);
    if (!is_numeric($count)) {
      throw new \Exception('Could not get civicrm_osdi_log table row count');
    }
    return $count;
  }

}
