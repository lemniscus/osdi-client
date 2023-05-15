<?php

namespace OsdiClient\ActionNetwork;

use Civi;
use CRM_OSDI_BAO_SyncProfile;
use GuzzleHttp\Client;
use Jsor;
use Jsor\HalClient\HttpClient\Guzzle6HttpClient;

class TestUtils {

  public static function createRemoteSystem(): \Civi\Osdi\ActionNetwork\RemoteSystem {
    $syncProfile = new CRM_OSDI_BAO_SyncProfile();
    $syncProfile->entry_point = 'https://actionnetwork.org/api/v2/';
    self::defineActionNetworkApiToken();
    $syncProfile->api_token = ACTION_NETWORK_TEST_API_TOKEN;
    $syncProfile->is_default = TRUE;
    $syncProfile->save(FALSE);

    //    $client = new Jsor\HalClient\HalClient(
    //      'https://actionnetwork.org/api/v2/', new CRM_OSDI_FixtureHttpClient());

    $httpClient = new Guzzle6HttpClient(new Client(['timeout' => 27]));
    $client = new Jsor\HalClient\HalClient('https://actionnetwork.org/api/v2/', $httpClient);

    \Civi\OsdiClient::container($syncProfile)->register(
      'RemoteSystem',
      'ActionNetwork',
      Civi\Osdi\ActionNetwork\RemoteSystem::class);

    return \Civi\OsdiClient::container()->initializeSingleton(
      'RemoteSystem',
      'ActionNetwork',
      $syncProfile,
      $client);
  }

  public static function createSyncProfile(): array {
    return \Civi\Api4\OsdiSyncProfile::create(FALSE)
      ->addValue('is_default', TRUE)
      ->addValue('remote_system', 'Civi\Osdi\ActionNetwork\RemoteSystem')
      ->addValue('entry_point', 'http://foo')
      ->addValue(
        'matcher',
        \Civi\Osdi\ActionNetwork\Matcher\Person\UniqueEmailOrFirstLastEmail::class)
      ->addValue(
        'mapper',
        \Civi\Osdi\ActionNetwork\Mapper\PersonBasic::class)
      ->execute()->single();
  }

  public static function defineActionNetworkApiToken(): string {
    if (!defined('ACTION_NETWORK_TEST_API_TOKEN')) {
      define(
        'ACTION_NETWORK_TEST_API_TOKEN',
        file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'apiToken')
      );
    }
    return ACTION_NETWORK_TEST_API_TOKEN;
  }

}
