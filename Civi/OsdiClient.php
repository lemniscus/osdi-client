<?php

namespace Civi;

use Civi\Osdi\Container;
use Civi\Osdi\Exception\InvalidArgumentException;

class OsdiClient {

  private static ?Container $containerSingleton = NULL;

  private static bool $containerSingletonWasMadeWithDefaultSyncProfile = FALSE;

  /**
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

    if (is_int($mixedSyncProfileParam)) {
      $syncProfileBAO = new \CRM_OSDI_BAO_SyncProfile();
      $syncProfileBAO->id = $mixedSyncProfileParam;
      if (!$syncProfileBAO->find(TRUE)) {
        throw new InvalidArgumentException();
      }
    }
    elseif (is_null($mixedSyncProfileParam)) {
      $syncProfileBAO = new \CRM_OSDI_BAO_SyncProfile();
      $syncProfileBAO->is_default = TRUE;
      if ($syncProfileBAO->find(TRUE) !== 1) {
        throw new InvalidArgumentException('There should be exactly 1 default sync profile');
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

    return self::$containerSingleton;
  }

  public static function containerWithDefaultSyncProfile(bool $refresh = FALSE) {
    if ($refresh || !self::$containerSingletonWasMadeWithDefaultSyncProfile) {
      self::$containerSingleton = NULL;
    }
    return self::container();
  }

}
