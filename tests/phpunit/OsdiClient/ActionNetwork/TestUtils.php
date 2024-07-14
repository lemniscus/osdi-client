<?php

namespace OsdiClient\ActionNetwork;

use Civi;
use GuzzleHttp\Client;
use Jsor;
use Jsor\HalClient\HttpClient\Guzzle6HttpClient;

class TestUtils {

  public static function createRemoteSystem(): \Civi\Osdi\ActionNetwork\RemoteSystem {
    $syncProfile = self::createSyncProfile();

    //    $client = new Jsor\HalClient\HalClient(
    //      'https://actionnetwork.org/api/v2/', new CRM_OSDI_FixtureHttpClient());

    $httpClient = new Guzzle6HttpClient(new Client(['timeout' => 7]));
    $client = new Jsor\HalClient\HalClient($syncProfile['entry_point'], $httpClient);

    $container = \Civi\OsdiClient::containerWithDefaultSyncProfile(TRUE);
    return $container->initializeSingleton(
      'RemoteSystem',
      'ActionNetwork',
      $container->getSyncProfile(),
      $client);
  }

  public static function createSyncProfile(): array {
    \Civi\Api4\OsdiSyncProfile::update(FALSE)
      ->addWhere('is_default', '=', TRUE)
      ->addValue('is_default', FALSE)
      ->execute();
    return \Civi\Api4\OsdiSyncProfile::save(FALSE)
      ->setMatch(['label'])
      ->addRecord([
        'is_default' => TRUE,
        'label' => 'SyncProfile created by ' . __CLASS__,
        'entry_point' => 'https://actionnetwork.org/api/v2/',
        'api_token' => file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'apiToken'),
        'classes' => [],
      ])
      ->execute()->first();
  }

  /**
   * Create a situation in which the object's modification time is one second
   * later than the modification time recorded in the Person Sync State (if any).
   */
  public static function makeItSeemLikePersonWasModifiedAfterLastSync(
    Civi\Osdi\CrudObjectInterface $person
  ): Civi\Osdi\PersonSyncState {
    $oneSecondBefore = function ($timeString): string {
      return date('Y-m-d H:i:s', strtotime($timeString) - 1);
    };

    $syncProfileId = Civi\OsdiClient::container()->getSyncProfileId();

    if (is_a($person, Civi\Osdi\LocalObjectInterface::class)) {
      $syncState = Civi\Osdi\PersonSyncState::getForLocalPerson($person, $syncProfileId);
      $syncState->setLocalPostSyncModifiedTime(
        $oneSecondBefore($syncState->getLocalPostSyncModifiedTime()));
    }
    elseif (is_a($person, Civi\Osdi\RemoteObjectInterface::class)) {
      $syncState = Civi\Osdi\PersonSyncState::getForRemotePerson($person, $syncProfileId);
      $syncState->setRemotePostSyncModifiedTime(
        $oneSecondBefore($syncState->getRemotePostSyncModifiedTime()));
    }

    $syncState->save();
    return $syncState;
  }

}
