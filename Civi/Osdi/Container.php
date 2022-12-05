<?php

namespace Civi\Osdi;

use Civi\Osdi\Exception\InvalidArgumentException;
use CRM_OSDI_BAO_SyncProfile;
use GuzzleHttp\Client;
use Jsor\HalClient\HttpClient\Guzzle6HttpClient;

class Container {

  public array $registry = [
    'RemoteSystem' => [
      'ActionNetwork' => \Civi\Osdi\ActionNetwork\RemoteSystem::class,
    ],
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

  private ?CRM_OSDI_BAO_SyncProfile $syncProfile;

  private array $singletons = [];

  /**
   * @param \CRM_OSDI_BAO_SyncProfile|null $syncProfile
   */
  public function __construct(CRM_OSDI_BAO_SyncProfile $syncProfile = NULL) {
    $this->syncProfile = $syncProfile;
  }

  public function canMake(string $category, string $key) {
    $class = $this->registry[$category][$key] ?? NULL;
    return !is_null($class);
  }

  public function getSyncProfileId(): ?int {
    if ($this->syncProfile) {
      return $this->syncProfile->id;
    }
    return NULL;
  }

  #[\ReturnTypeWillChange]
  public function make(string $category, string $key, ...$constructorParams) {
    $class = $this->registry[$category][$key] ?? NULL;
    if (is_null($class)) {
      throw new InvalidArgumentException();
    }
    if (empty($constructorParams)) {
      $constructorParams = $this->getDefaultConstructorParams($category, $key);
    }
    return new $class(...$constructorParams);
  }

  public function register(string $category, string $key, string $class) {
    $this->registry[$category][$key] = $class;
  }

  public function getSingle(string $category, string $key, ...$constructorParams) {
    $singletons = &$this->singletons;
    $singleton = $singletons[$category][$key] ?? NULL;
    if (is_null($singleton)) {
      $singleton = $this->make($category, $key, ...$constructorParams);
      $singletons[$category][$key] = $singleton;
    }
    return $singleton;
  }

  public function initializeSingleton(string $category, string $key, ...$constructorParams) {
    unset($this->singletons[$category][$key]);
    return $this->getSingle($category, $key, ...$constructorParams);
  }

  public function initializeRemoteSystem() {
    return $this->initializeSingleton('RemoteSystem', 'ActionNetwork');
  }

  private function getDefaultConstructorParams(string $category, string $key): array {
    if ($category === 'OsdiObject') {
      // hard-coding 'ActionNetwork' because it's the only kind we have for now
      return [$this->getSingle('RemoteSystem', 'ActionNetwork')];
    }
    if ($category === 'RemoteSystem' && $key === 'ActionNetwork') {
      return [$this->syncProfile, $this->getDefaultHalClient()];
    }
    return [];
  }

  private function getDefaultHalClient(): \Jsor\HalClient\HalClient {
    $httpClient = new Guzzle6HttpClient(new Client(['timeout' => 27]));
    return new \Jsor\HalClient\HalClient(
      $this->syncProfile->entry_point, $httpClient);
  }

}
