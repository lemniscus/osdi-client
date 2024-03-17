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
    return \Civi\Api4\OsdiSyncProfile::create(FALSE)
      ->addValue('is_default', TRUE)
      ->addValue('label', 'SyncProfile created by ' . __CLASS__)
      ->addValue('entry_point', 'https://actionnetwork.org/api/v2/')
      ->addValue('api_token', file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'apiToken'))
      ->addValue('classes', [])
      ->execute()->single();
  }

}
