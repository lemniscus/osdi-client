<?php


namespace Civi\Osdi\ActionNetwork;

use Civi\Osdi\ActionNetwork\Object\Person;
use Civi\Osdi\ActionNetwork\Object\Tag;
use Civi\Osdi\ActionNetwork\Object\Tagging;
use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\Factory;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\Result\Save;
use CRM_OSDI_ExtensionUtil as E;
use GuzzleHttp\Client;
use Jsor\HalClient\Exception\BadResponseException;
use Jsor\HalClient\HalClient;
use Jsor\HalClient\HalClientInterface;
use Jsor\HalClient\HalLink;
use Jsor\HalClient\HalResource;
use Jsor\HalClient\HttpClient\Guzzle6HttpClient;

class RemoteSystem implements \Civi\Osdi\RemoteSystemInterface {

  private HalClientInterface $client;

  private \CRM_OSDI_BAO_SyncProfile $systemProfile;

  public function __construct(?\CRM_OSDI_BAO_SyncProfile $systemProfile,
                              ?HalClientInterface $client = NULL) {
    if ($systemProfile) {
      $this->systemProfile = $systemProfile;
    }
    if ($client) {
      $this->client = $client;
    }
  }

  public function getEntryPoint(): string {
    return (string) $this->getClient()->getRootUrl();
  }

  public function makeOsdiObject(
      string $type,
      ?HalResource $resource = NULL
  ): RemoteObjectInterface {
    return Factory::make('OsdiObject', $type, $this, $resource);
  }

  public function fetchObjectByUrl(string $type, string $url): RemoteObjectInterface {
    try {
      $resource = $this->linkify($url)->get();
    }
    catch (BadResponseException $e) {
      if (404 === $e->getCode()) {
        throw new EmptyResultException('Nothing found at "%s"', $url);
      }
    }
    return $this->makeOsdiObject($type, $resource);
  }

  public function fetch(RemoteObjectInterface $osdiObject): HalResource {
    if (!($url = $osdiObject->getUrlForRead())) {
      throw new InvalidArgumentException('Cannot fetch: the %s has no url',
        get_class($osdiObject));
    }
    try {
      $resource = $this->linkify($url)->get();
    }
    catch (BadResponseException $e) {
      if (404 === $e->getCode()) {
        throw new EmptyResultException('Nothing found at "%s"', $url);
      }
    }
    return $resource;
  }

  /**
   * @param string $objectType such as 'osdi:people'
   * @param array $criteria
   *   [[$key, $operator, $value], [$key, $operator, $value]...]
   *   where $operator is 'eq', 'lt', or 'gt'
   */
  public function find(string $objectType, array $criteria): RemoteFindResult {
    $entitiesThatSupportOData = ['osdi:people', 'osdi:signatures', 'osdi:outreaches'];
    if (!in_array($objectType, $entitiesThatSupportOData)) {
      throw new InvalidArgumentException(
        '%s is an unsupported object type for find', $objectType);
    }
    $filterClauses = [];
    foreach ($criteria as $criterion) {
      if (count($criterion) !== 3) {
        throw new InvalidArgumentException('Incorrect parameter format for find');
      }
      [$key, $operator, $value] = $criterion;
      if (in_array($operator, ['eq', 'lt', 'gt'])) {
        $filterClauses[] = "$key $operator '" . addslashes($value) . "'";
      }
      else {
        throw new InvalidArgumentException("Operator '$operator' is not implemented");
      }
    }
    $endpoint = $this->getEndpointFor($objectType);
    $query = implode(' and ', $filterClauses);
    $href = $endpoint->getHref() . "?filter=$query";
    $endPointWithQuery = $this->linkify($href);
    return new RemoteFindResult($this, $objectType, $endPointWithQuery);
  }

  public function findAll(string $objectType): RemoteFindResult {
    $endpoint = $this->getEndpointFor($objectType);
    return new RemoteFindResult($this, $objectType, $endpoint);
  }

  public function save(RemoteObjectInterface $osdiObject): HalResource {
    if ($osdiObject->getId()) {
      try {
        $result = $this->updateOnRemoteSystem($osdiObject);
      }
      catch (\Throwable $e) {
        $result = $this->createOnRemoteSystem($osdiObject);
      }
    }
    else {
      $result = $this->createOnRemoteSystem($osdiObject);
    }
    return $result;
  }

  public function delete(RemoteObjectInterface $osdiObject) {
    $endpoint = $this->linkify($osdiObject->getUrlForDelete());
    return $endpoint->delete();
  }

  /**
   * @deprecated
   */
  public function trySave(RemoteObjectInterface $objectBeingSaved): Save {
    $statusCode = $statusMessage = $context = NULL;

    if ('osdi:people' === $objectBeingSaved->getType()) {
      /** @var \Civi\Osdi\ActionNetwork\Object\Person $objectBeingSaved */
      [$statusCode, $statusMessage, $context] = $objectBeingSaved->checkForEmailAddressConflict();
    }

    if ($statusCode === Save::ERROR) {
      return new Save($objectBeingSaved, $statusCode, $statusMessage, $context);
    }

    $objectBeforeSaving = clone $objectBeingSaved;
    $changesBeingSaved = $objectBeingSaved->diffChanges()->toArray();

    try {
      $objectBeingSaved->save();
      $statusCode = Save::SUCCESS;
      $context = ['diff' => $changesBeingSaved];
    }

    catch (\Throwable $e) {
      $statusCode = Save::ERROR;
      $statusMessage = $e->getMessage();
      $context = [
        'object' => $objectBeingSaved,
        'exception' => $e,
      ];
      return new Save(NULL, $statusCode, $statusMessage, $context);
    }

    if (!$objectBeingSaved->isSupersetOf(
      $objectBeforeSaving,
      ['identifiers', 'createdDate', 'modifiedDate']
    )) {
      $statusCode = Save::ERROR;
      $statusMessage = E::ts(
        'Some or all of the %1 object could not be saved.',
        [1 => $objectBeforeSaving->getType()],
      );
      $context = [
        'diff with left=sent, right=response' => $objectBeforeSaving::diff($objectBeforeSaving, $objectBeingSaved)->toArray(),
        'intended changes' => $changesBeingSaved,
        'sent' => $objectBeforeSaving->getArrayForCreate(),
        'response' => $objectBeingSaved->getArrayForCreate(),
      ];
    }

    return new Save($objectBeingSaved, $statusCode, $statusMessage, $context);
  }

  /**
   * @return \Jsor\HalClient\HalResource|\Psr\Http\Message\ResponseInterface
   */
  private function getRootResource() {
    return $this->getClient()->root();
  }

  /**
   * @param string $objectType
   *
   * @return \Jsor\HalClient\HalLink|null
   * @throws InvalidArgumentException
   */
  private function getEndpointFor(string $objectType): ?HalLink {
    try {
      return $this->linkify($this->constructUrlFor($objectType));
    }
    catch (\Throwable $e) {
      throw new InvalidArgumentException('Cannot get endpoint for "%s"', $objectType);
    }
  }

  private function updateOnRemoteSystem(RemoteObjectInterface $osdiObject) {
    $endpoint = $this->linkify($osdiObject->getUrlForUpdate());
    return $endpoint->put([], ['body' => $osdiObject->getArrayForUpdate()]);
  }

  /**
   * @param \Civi\Osdi\RemoteObjectInterface $osdiObject
   *
   * @return \Jsor\HalClient\HalResource|\Psr\Http\Message\ResponseInterface
   */
  private function createOnRemoteSystem(RemoteObjectInterface $osdiObject) {
    $endpoint = $this->linkify($osdiObject->getUrlForCreate());
    return $endpoint->post([], ['body' => $osdiObject->getArrayForCreate()]);
  }

  public function getClient(): HalClientInterface {
    if (empty($this->client)) {
      $httpClient = new Guzzle6HttpClient(new Client(['timeout' => 10]));

      $entryPoint = $this->systemProfile ? $this->systemProfile->entry_point : '';
      $this->client = new HalClient($entryPoint, $httpClient);
    }
    if (empty($this->client->getHeader('OSDI-API-Token'))) {
      $apiToken = isset($this->systemProfile) ? $this->systemProfile->api_token : '';
      if ($apiToken) {
        $this->client = $this->client->withHeader('OSDI-API-Token', $apiToken);
      }
    }
    return $this->client;
  }

  protected function getEndPointHrefFor(string $objectType): ?string {
    $urlMap = [
      'osdi:advocacy_campaigns' => 'https://actionnetwork.org/api/v2/advocacy_campaigns',
      'osdi:donations' => 'https://actionnetwork.org/api/v2/donations',
      'osdi:events' => 'https://actionnetwork.org/api/v2/events',
      'osdi:forms' => 'https://actionnetwork.org/api/v2/forms',
      'osdi:fundraising_pages' => 'https://actionnetwork.org/api/v2/fundraising_pages',
      'osdi:lists' => 'https://actionnetwork.org/api/v2/lists',
      'osdi:messages' => 'https://actionnetwork.org/api/v2/messages',
      'osdi:people' => 'https://actionnetwork.org/api/v2/people',
      'osdi:person_signup_helper' => 'https://actionnetwork.org/api/v2/people',
      'osdi:petitions' => 'https://actionnetwork.org/api/v2/petitions',
      'osdi:queries' => 'https://actionnetwork.org/api/v2/queries',
      'osdi:tags' => 'https://actionnetwork.org/api/v2/tags',
      'osdi:wrappers' => 'https://actionnetwork.org/api/v2/wrappers',
    ];
    return $urlMap[$objectType] ?? NULL;
  }

  /**
   * @param string $objectType
   *
   * @return string|null
   * @throws EmptyResultException
   */
  private function constructUrlFor(string $objectType): ?string {
    $url = $this->getEndPointHrefFor($objectType);
    if ($url) {
      return $url;
    }
    throw new EmptyResultException('Could not find url for "%s"', $objectType);
  }

  public function linkify(string $url): HalLink {
    return new HalLink($this->getClient(), $url);
  }

  public static function formatDateTime(int $unixTimeStamp) {
    return gmdate('Y-m-d\TH:i:s\Z', $unixTimeStamp);
  }

}
