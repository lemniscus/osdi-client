<?php

namespace Civi\Osdi;

use Civi\Osdi\Exception\InvalidArgumentException;
use CRM_OSDI_BAO_SyncProfile;
use GuzzleHttp\Client;
use Jsor\HalClient\HttpClient\Guzzle6HttpClient;

class Container {

  protected array $registry = [
    'RemoteSystem' => [
      'ActionNetwork' => \Civi\Osdi\ActionNetwork\RemoteSystem::class,
    ],
    'LocalObject' => [
      'Donation' => LocalObject\DonationBasic::class,
      'Person' => LocalObject\PersonBasic::class,
      'Tag' => LocalObject\TagBasic::class,
      'Tagging' => LocalObject\TaggingBasic::class,
    ],
    'OsdiObject' => [
      'osdi:donations' => ActionNetwork\Object\Donation::class,
      'osdi:fundraising_pages' => ActionNetwork\Object\FundraisingPage::class,
      'osdi:people' => ActionNetwork\Object\Person::class,
      'osdi:tags' => ActionNetwork\Object\Tag::class,
      'osdi:taggings' => ActionNetwork\Object\Tagging::class,
    ],
    'Mapper' => [
      'Donation' => ActionNetwork\Mapper\DonationBasic::class,
      'Person' => ActionNetwork\Mapper\PersonBasic::class,
      'Tag' => ActionNetwork\Mapper\TagBasic::class,
      'Tagging' => ActionNetwork\Mapper\TaggingBasic::class,
    ],
    'Matcher' => [
      'Donation' => ActionNetwork\Matcher\DonationBasic::class,
      'Person' => ActionNetwork\Matcher\Person\UniqueEmailOrFirstLastEmail::class,
      'Tag' => ActionNetwork\Matcher\TagBasic::class,
      'Tagging' => ActionNetwork\Matcher\TaggingBasic::class,
    ],
    'SingleSyncer' => [
      'Donation' => ActionNetwork\SingleSyncer\DonationBasic::class,
      'Person' => ActionNetwork\SingleSyncer\PersonBasic::class,
      'Tag' => ActionNetwork\SingleSyncer\TagBasic::class,
      'Tagging' => ActionNetwork\SingleSyncer\TaggingBasic::class,
    ],
    'BatchSyncer' => [
      'Donation' => ActionNetwork\BatchSyncer\DonationBasic::class,
      'Person' => ActionNetwork\BatchSyncer\PersonBasic::class,
      'Tagging' => ActionNetwork\BatchSyncer\TaggingBasic::class,
    ],
    'CrmEventResponder' => [
      'Contact' => ActionNetwork\CrmEventResponder\PersonBasic::class,
      'EntityTag' => ActionNetwork\CrmEventResponder\TaggingBasic::class,
    ],
  ];

  private ?CRM_OSDI_BAO_SyncProfile $syncProfile;

  /**
   * @deprecated Should be refactored out once we remove RemoteSystem's
   * dependency on CRM_OSDI_BAO_SyncProfile
   *
   * @return \CRM_OSDI_BAO_SyncProfile|null
   */
  public function getSyncProfile(): ?CRM_OSDI_BAO_SyncProfile {
    return $this->syncProfile;
  }

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

  public function callStatic(string $category, string $key, string $function, ...$functionParams) {
    $class = $this->registry[$category][$key] ?? NULL;
    if (is_null($class)) {
      throw new InvalidArgumentException("Container does not have a class for '$category', '$key'");
    }
    return call_user_func([$class, $function], ...$functionParams);
  }

  #[\ReturnTypeWillChange]
  public function make(string $category, string $key, ...$constructorParams) {
    $class = $this->registry[$category][$key] ?? NULL;
    if (is_null($class)) {
      throw new InvalidArgumentException("Container cannot make '$category', '$key'");
    }
    if (empty($constructorParams)) {
      $constructorParams = $this->getDefaultConstructorParams($category, $key);
    }
    return new $class(...$constructorParams);
  }

  public function register(string $category, string $key, ?string $class) {
    if ('*' === $category) {
      if (!('*' === $key && is_null($class))) {
        throw new InvalidArgumentException('Invalid argument(s) passed to '
          . __CLASS__ . '::' . __FUNCTION__);
      }
      $this->registry = [];
    }
    elseif ('*' === $key) {
      if (!is_null($class)) {
        throw new InvalidArgumentException('Invalid argument(s) passed to '
          . __CLASS__ . '::' . __FUNCTION__);
      }
      $this->registry[$category] = [];
    }
    else {
      $this->registry[$category][$key] = $class;
    }
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
