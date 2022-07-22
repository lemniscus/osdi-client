<?php


namespace Civi\Osdi\ActionNetwork;

use Civi\Osdi\ActionNetwork\Object\Person;
use Civi\Osdi\ActionNetwork\Object\Tag;
use Civi\Osdi\ActionNetwork\Object\Tagging;
use Civi\Osdi\RemoteObjectInterface;
use CRM_OSDI_ExtensionUtil as E;
use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\SaveResult;
use GuzzleHttp\Client;
use Jsor\HalClient\Exception\BadResponseException;
use Jsor\HalClient\HalClient;
use Jsor\HalClient\HalClientInterface;
use Jsor\HalClient\HalLink;
use Jsor\HalClient\HalResource;
use Jsor\HalClient\HttpClient\Guzzle6HttpClient;

class RemoteSystem implements \Civi\Osdi\RemoteSystemInterface {

  /**
   * @var \Jsor\HalClient\HalClientInterface
   */
  private $client;

  /**
   * @var \CRM_OSDI_BAO_SyncProfile
   */
  private $systemProfile;

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
      ?HalResource $resource = NULL,
      ?array $initData = NULL): RemoteObjectInterface {
    if ('osdi:people' === $type) {
      return new Person($this, $resource);
    }
    if ('osdi:tags' === $type) {
      return new Tag($this, $resource);
    }
    if ('osdi:taggings' === $type) {
      return new Tagging($this, $resource);
    }
    throw new InvalidArgumentException('Cannot make OSDI object of type "%s"', $type);
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
   * @deprecated
   */
  public function fetchById(string $type, string $id): RemoteObjectInterface {
    if (0 === strlen($id)) {
      throw new InvalidArgumentException("No ID supplied to fetchById");
    }
    $endpoint = $this->getEndpointFor($type, $id);
    try {
      return $this->makeOsdiObject($type, $endpoint->get());
    }
    catch (BadResponseException $e) {
      if (404 === $e->getCode()) {
        throw new EmptyResultException('Nothing found at "%s"', $endpoint->getHref());
      }
      throw $e;
    }
  }

  /**
   * @deprecated
   */
  public function fetchPersonById(string $id): Person {
    $person = new Person($this);
    $person->setId($id);
    return $person->load();
  }

  /**
   * @param string $objectType such as 'osdi:people'
   * @param array $criteria
   *   [[$key, $operator, $value], [$key, $operator, $value]...]
   *   where $operator is 'eq', 'lt', or 'gt'
   */
  public function find(string $objectType, array $criteria): \Civi\Osdi\ResultCollection {
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
    return new ResultCollection($this, $objectType, $endPointWithQuery);
  }

  public function save(RemoteObjectInterface $osdiObject): HalResource {
    if ($id = $osdiObject->getId()) {
      try {
        $result = $this->updateOnRemoteSystem($osdiObject, $id);
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

  public function trySave(RemoteObjectInterface $objectBeingSaved): SaveResult {
    $statusCode = $statusMessage = $context = NULL;

    if ('osdi:people' === $objectBeingSaved->getType()) {
      /** @var \Civi\Osdi\ActionNetwork\Object\Person $objectBeingSaved */
      [$statusCode, $statusMessage, $context] = $objectBeingSaved->checkForEmailAddressConflict();
    }

    if ($statusCode === SaveResult::ERROR) {
      return new SaveResult($objectBeingSaved, $statusCode, $statusMessage, $context);
    }

    $objectBeforeSaving = clone $objectBeingSaved;
    $changesBeingSaved = $objectBeingSaved->diffChanges()->toArray();

    try {
      $objectBeingSaved->save();
      $statusCode = SaveResult::SUCCESS;
      $context = ['diff' => $changesBeingSaved];
    }

    catch (\Throwable $e) {
      $statusCode = SaveResult::ERROR;
      $statusMessage = $e->getMessage();
      $context = [
        'object' => $objectBeingSaved,
        'exception' => $e,
      ];
      return new SaveResult(NULL, $statusCode, $statusMessage, $context);
    }

    if (!$objectBeingSaved->isSupersetOf(
      $objectBeforeSaving,
      ['identifiers', 'createdDate', 'modifiedDate']
    )) {
      $statusCode = SaveResult::ERROR;
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

    return new SaveResult($objectBeingSaved, $statusCode, $statusMessage, $context);
  }

  /**
   * @return \Jsor\HalClient\HalResource|\Psr\Http\Message\ResponseInterface
   */
  private function getRootResource() {
    return $this->getClient()->root();
  }

  /**
   * @param string $objectType
   * @param string|null $id
   * @return \Jsor\HalClient\HalLink|null
   * @throws InvalidArgumentException
   */
  private function getEndpointFor(string $objectType, string $id = NULL): ?HalLink {
    try {
      return $this->linkify($this->constructUrlFor($objectType, $id));
    }
    catch (\Throwable $e) {
      throw new InvalidArgumentException('Cannot get endpoint for "%s"', $objectType);
    }
  }

  private function updateOnRemoteSystem(RemoteObjectInterface $osdiObject, string $id) {
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
      $apiToken = $this->systemProfile ? $this->systemProfile->api_token : '';
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
    $url = $urlMap[$objectType] ?? NULL;
    return $url;
  }

  /**
   * @param string $objectType
   * @param string|null $id
   * @return string|null
   * @throws EmptyResultException
   */
  private function constructUrlFor(string $objectType, string $id = NULL): ?string {
    $url = $this->getEndPointHrefFor($objectType);
    if ($url) {
      return $url . ($id ? "/$id" : '');
    }
    throw new EmptyResultException('Could not find url for "%s"', $objectType);
  }

  private function linkify(string $url): HalLink {
    return new HalLink($this->getClient(), $url);
  }

  public static function formatDateTime(int $unixTimeStamp) {
    return gmdate('Y-m-d\TH:i:s\Z', $unixTimeStamp);
  }

}
