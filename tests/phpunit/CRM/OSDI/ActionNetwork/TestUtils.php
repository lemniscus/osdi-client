<?php

use GuzzleHttp\Client;
use Jsor\HalClient\HttpClient\Guzzle6HttpClient;

class CRM_OSDI_ActionNetwork_TestUtils {

  public static function createRemoteSystem(): \Civi\Osdi\ActionNetwork\RemoteSystem {
    $systemProfile = new CRM_OSDI_BAO_SyncProfile();
    $systemProfile->entry_point = 'https://actionnetwork.org/api/v2/';
    if (!defined('N2F_ACTION_NETWORK_API_TOKEN')) {
      define(
        'N2F_ACTION_NETWORK_API_TOKEN',
        file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'apiToken')
      );
    }
    $systemProfile->api_token = N2F_ACTION_NETWORK_API_TOKEN;
    //    $client = new Jsor\HalClient\HalClient(
    //      'https://actionnetwork.org/api/v2/', new CRM_OSDI_FixtureHttpClient()
    //    );
    $httpClient = new Guzzle6HttpClient(new Client(['timeout' => 27]));
    $client = new Jsor\HalClient\HalClient('https://actionnetwork.org/api/v2/', $httpClient);
    return new Civi\Osdi\ActionNetwork\RemoteSystem($systemProfile, $client);
  }

  public static function createSyncProfile(): array {
    return \Civi\Api4\OsdiSyncProfile::create(FALSE)
      ->addValue('is_default', TRUE)
      ->addValue('remote_system', 'Civi\Osdi\ActionNetwork\RemoteSystem')
      ->addValue('entry_point', 'http://foo')
      ->addValue(
        'matcher',
        \Civi\Osdi\ActionNetwork\Matcher\Person\OneToOneEmailOrFirstLastEmail::class)
      ->addValue(
        'mapper',
        \Civi\Osdi\ActionNetwork\Mapper\Person\Basic::class)
      ->execute()->single();
  }

}
