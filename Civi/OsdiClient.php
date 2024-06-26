<?php

namespace Civi;

use Civi\Api4\OsdiSyncProfile;
use Civi\Osdi\Container;
use Civi\Osdi\Exception\InvalidArgumentException;

class OsdiClient {

  private static ?Container $containerSingleton = NULL;

  private static bool $containerSingletonWasMadeWithDefaultSyncProfile = FALSE;

  /**
   * Without the parameter, return the current OsdiClient container. If none
   * exists, create and initialize it using the default SyncProfile.
   *
   * If a SyncProfile parameter is given, replace the current container (if any)
   * with a new one, and initialize it using the given SyncProfile.
   *
   * Our intention is to drop the use of BAOs, and use API4 instead, but that
   * will require some changes to RemoteSystemInterface implementations.
   *
   * @param int|\CRM_OSDI_BAO_SyncProfile|NULL $mixedSyncProfileParam
   *
   * @return \Civi\Osdi\Container
   */
  public static function container($mixedSyncProfileParam = NULL) {
    if (self::$containerSingleton && !$mixedSyncProfileParam) {
      return self::$containerSingleton;
    }

    // we're creating a new container

    $paramIsInteger = is_int($mixedSyncProfileParam)
      || (is_string($mixedSyncProfileParam)
         && ((string) (int) $mixedSyncProfileParam === $mixedSyncProfileParam));

    if ($paramIsInteger) {
      $syncProfileBAO = new \CRM_OSDI_BAO_SyncProfile();
      $syncProfileBAO->id = $mixedSyncProfileParam;
      if (!$syncProfileBAO->find(TRUE)) {
        throw new InvalidArgumentException("Invalid Sync Profile id: $mixedSyncProfileParam");
      }
    }
    elseif (is_null($mixedSyncProfileParam)) {
      $syncProfileBAO = new \CRM_OSDI_BAO_SyncProfile();
      $syncProfileBAO->is_default = TRUE;
      $foundBAOs = $syncProfileBAO->find(TRUE);
      if ($foundBAOs !== 1) {
        throw new InvalidArgumentException('There should be exactly 1 default sync profile, %u found', $foundBAOs);
      }
    }
    elseif (is_a($mixedSyncProfileParam, \CRM_OSDI_BAO_SyncProfile::class)) {
      $syncProfileBAO = $mixedSyncProfileParam;
    }
    else {
      throw new InvalidArgumentException();
    }

    self::$containerSingletonWasMadeWithDefaultSyncProfile =
      (bool) $syncProfileBAO->is_default;

    self::$containerSingleton = new Container($syncProfileBAO);
    self::$containerSingleton->initializeRemoteSystem();

    $classesToRegister = json_decode($syncProfileBAO->classes ?? '', TRUE);
    if ($classesToRegister) {
      foreach ($classesToRegister as $category => $entries) {
        foreach ($entries as $key => $className) {
          self::$containerSingleton->register($category, $key, $className);
        }
      }
    }

    // if we had no container before, hook listeners might not have been registered.
    osdi_client_add_syncprofile_dependent_listeners();

    return self::$containerSingleton;
  }

  public static function containerIsInitialized(): bool {
    return !empty(self::$containerSingleton);
  }

  public static function containerWithDefaultSyncProfile(bool $refresh = FALSE) {
    if ($refresh || !self::$containerSingletonWasMadeWithDefaultSyncProfile) {
      self::$containerSingleton = NULL;
    }
    return self::container();
  }

  public static function postSaveOsdiSyncProfile(string $op, string $objectName, ?int $objectId, &$objectRef) {
    if ($op !== 'create' && $op !== 'edit') {
      return;
    }
    /** @var \CRM_OSDI_DAO_SyncProfile $objectRef */
    if ($objectRef->is_default) {
      $profileArray = OsdiSyncProfile::get(FALSE)
        ->addWhere('id', '=', $objectId)
        ->execute()->single();
      \Civi::settings()->set('osdiClient.defaultSyncProfile', $profileArray);

      // if we were missing a default SyncProfile before, hook listeners might
      // not have been registered.
      osdi_client_add_syncprofile_dependent_listeners();
    }
  }

}
