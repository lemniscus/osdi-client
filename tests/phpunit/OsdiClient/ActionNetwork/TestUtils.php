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

}
