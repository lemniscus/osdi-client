<?php

namespace Civi\Api4;

use Civi\Api4\Patch\DAOSaveAction;

/**
 * OsdiPersonSyncState entity.
 *
 * Provided by the OSDI Client extension.
 *
 * @package Civi\Api4
 * @dao CRM_OSDI_DAO_PersonSyncState
 */
class OsdiPersonSyncState extends Generic\DAOEntity {

  const syncOriginLocal = 0;
  const syncOriginRemote = 1;

}
