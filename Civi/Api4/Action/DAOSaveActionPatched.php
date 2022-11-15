<?php

namespace Civi\Api4\Action;

use Civi\Api4\Utils\CoreUtil;

/**
 * CAN BE REMOVED ONCE https://github.com/civicrm/civicrm-core/pull/24971
 * IS RELEASED -- UPDATE THE REQUIRED CIVI VERSION OF THIS EXTENSION
 */
class DAOSaveActionPatched extends \Civi\Api4\Generic\DAOSaveAction {

  protected function matchExisting(&$record) {
    $primaryKey = CoreUtil::getIdFieldName($this->getEntityName());
    if (empty($record[$primaryKey]) && !empty($this->match)) {
      $where = [];
      foreach ($record as $key => $val) {
        if (in_array($key, $this->match, TRUE)) {
          if ($val === '' || is_null($val)) {
            // If we want to match empty string we have to match on NULL/''
            $where[] = [$key, 'IS EMPTY'];
          }
          else {
            $where[] = [$key, '=', $val];
          }
        }
      }
      if (count($where) === count($this->match)) {
        $existing = civicrm_api4($this->getEntityName(), 'get', [
          'select' => [$primaryKey],
          'where' => $where,
          'checkPermissions' => $this->checkPermissions,
          'limit' => 2,
        ]);
        if ($existing->count() === 1) {
          $record[$primaryKey] = $existing->first()[$primaryKey];
        }
      }
    }
  }

}
