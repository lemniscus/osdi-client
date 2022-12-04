<?php

namespace Civi\Osdi;

use Civi\Osdi\Exception\InvalidArgumentException;
use GuzzleHttp\Client;
use Jsor\HalClient\HttpClient\Guzzle6HttpClient;

class Container {

  public array $registry = [
    'LocalObject' => [
      'Person' => LocalObject\PersonBasic::class,
      'Tagging' => LocalObject\TaggingBasic::class,
    ],
    'OsdiObject' => [
      'osdi:people' => ActionNetwork\Object\Person::class,
      'osdi:tags' => ActionNetwork\Object\Tag::class,
      'osdi:taggings' => ActionNetwork\Object\Tagging::class,
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

  public function canMake(string $category, string $key) {
    $class = $this->registry[$category][$key] ?? NULL;
    return !is_null($class);
  }

  #[\ReturnTypeWillChange]
  public function make(string $category, string $key, ...$constructorParams) {
    $class = $this->registry[$category][$key] ?? NULL;
    if (is_null($class)) {
      throw new InvalidArgumentException();
    }
    return new $class(...$constructorParams);
  }

  public function register(string $category, string $key, string $class) {
    $this->registry[$category][$key] = $class;
  }

  public function getSingle(string $category, string $key, ...$constructorParams) {
    $singletons = &\Civi::$statics['osdiClient.singletons'];
    $singleton = $singletons[$category][$key] ?? NULL;
    if (is_null($singleton)) {
      $singleton = static::make($category, $key, ...$constructorParams);
      $singletons[$category][$key] = $singleton;
    }
    return $singleton;
  }

  public function initializeSingleton(string $category, string $key, ...$constructorParams) {
    unset(\Civi::$statics['osdiClient.singletons'][$category][$key]);
    return $this->getSingle($category, $key, ...$constructorParams);
  }

  public function initializeRemoteSystem(\CRM_OSDI_BAO_SyncProfile $syncProfile) {
    $httpClient = new Guzzle6HttpClient(new Client(['timeout' => 27]));
    $client = new \Jsor\HalClient\HalClient($syncProfile->entry_point, $httpClient);

    $this->register(
      'RemoteSystem',
      'ActionNetwork',
      \Civi\Osdi\ActionNetwork\RemoteSystem::class);

    return $this->initializeSingleton(
      'RemoteSystem',
      'ActionNetwork',
      $syncProfile,
      $client);
  }

}
