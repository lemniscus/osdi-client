<?php

namespace Civi\Osdi;

use Civi\Osdi\Exception\InvalidArgumentException;
use GuzzleHttp\Client;
use Jsor\HalClient\HttpClient\Guzzle6HttpClient;

class Factory {

  public static array $registry = [
    'LocalObject' => [
      'Person' => LocalObject\PersonBasic::class,
      'Tagging' => LocalObject\TaggingBasic::class,
    ],
    'OsdiObject' => [
      'osdi:people' => ActionNetwork\Object\Person::class,
      'osdi:tags' => ActionNetwork\Object\Tag::class,
      'osdi:taggings' => ActionNetwork\Object\Tagging::class,
      'osdi:fundraising_pages' => ActionNetwork\Object\FundraisingPage::class,
    ],
    'Mapper' => [
      'Person' => ActionNetwork\Mapper\PersonBasic::class,
      'Tag' => ActionNetwork\Mapper\TagBasic::class,
      'Tagging' => ActionNetwork\Mapper\TaggingBasic::class,
    ],
    'Matcher' => [
      'Person' => ActionNetwork\Matcher\Person\UniqueEmailOrFirstLastEmail::class,
      'Tag' => ActionNetwork\Matcher\TagBasic::class,
      'Tagging' => ActionNetwork\Matcher\TaggingBasic::class,
    ],
    'SingleSyncer' => [
      'Person' => ActionNetwork\SingleSyncer\Person\PersonBasic::class,
      'Tag' => ActionNetwork\SingleSyncer\TagBasic::class,
      'Tagging' => ActionNetwork\SingleSyncer\TaggingBasic::class,
    ],
    'BatchSyncer' => [
      'Person' => ActionNetwork\BatchSyncer\PersonBasic::class,
      'Tagging' => ActionNetwork\BatchSyncer\TaggingBasic::class,
    ],
    'CrmEventResponder' => [
      'Contact' => ActionNetwork\CrmEventResponder\PersonBasic::class,
      'EntityTag' => ActionNetwork\CrmEventResponder\TaggingBasic::class,
    ],
  ];

  public static function canMake(string $category, string $key) {
    $class = self::$registry[$category][$key] ?? NULL;
    return !is_null($class);
  }

  #[\ReturnTypeWillChange]
  public static function make(string $category, string $key, ...$constructorParams) {
    $class = self::$registry[$category][$key] ?? NULL;
    if (is_null($class)) {
      throw new InvalidArgumentException();
    }
    return new $class(...$constructorParams);
  }

  public static function register(string $category, string $key, string $class) {
    self::$registry[$category][$key] = $class;
  }

  public static function singleton(string $category, string $key, ...$constructorParams) {
    $singletons = &\Civi::$statics['osdiClient.singletons'];
    $singleton = $singletons[$category][$key] ?? NULL;
    if (is_null($singleton)) {
      $singleton = static::make($category, $key, ...$constructorParams);
      $singletons[$category][$key] = $singleton;
    }
    return $singleton;
  }

  public static function initializeRemoteSystem(string $apiToken) {
    $systemProfile = new \CRM_OSDI_BAO_SyncProfile();
    $systemProfile->entry_point = 'https://actionnetwork.org/api/v2/';
    $systemProfile->api_token = $apiToken;
    $httpClient = new Guzzle6HttpClient(new Client(['timeout' => 27]));
    $client = new \Jsor\HalClient\HalClient('https://actionnetwork.org/api/v2/', $httpClient);

    self::register(
      'RemoteSystem',
      'ActionNetwork',
      \Civi\Osdi\ActionNetwork\RemoteSystem::class);

    return self::singleton(
      'RemoteSystem',
      'ActionNetwork',
      $systemProfile,
      $client);
  }

}
