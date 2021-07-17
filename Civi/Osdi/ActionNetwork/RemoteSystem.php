<?php

namespace Civi\Osdi\ActionNetwork;

use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\RemoteObjectInterface;
use Jsor\HalClient\Exception\BadResponseException;
use Jsor\HalClient\HalClient;
use Jsor\HalClient\HalClientInterface;
use Jsor\HalClient\HalLink;
use Jsor\HalClient\HalResource;

class RemoteSystem implements \Civi\Osdi\RemoteSystemInterface {

  /**
   * @var \Jsor\HalClient\HalClientInterface
   */
  private $client;

  /**
   * @var \CRM_OSDI_BAO_SyncProfile
   */
  private $systemProfile;

  public function __construct(?\CRM_OSDI_BAO_SyncProfile $systemProfile, ?HalClientInterface $client) {
    if ($systemProfile) {
      $this->systemProfile = $systemProfile;
    }
    if ($client) {
      $this->client = $client;
    }
  }

  public function makeOsdiObject(
    string $type,
    ?HalResource $resource = NULL,
    ?array $initData = NULL): RemoteObjectInterface {
    if ('osdi:people' === $type) {
      return new OsdiPerson($resource, $initData);
    }
    if ('osdi:taggings' === $type) {
      return new OsdiTagging($resource, $initData);
    }
    if (in_array($type, ['osdi:tags', 'osdi:advocacy_campaigns'])) {
      return new \Civi\Osdi\ActionNetwork\OsdiObject($type, $resource, $initData);
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

  /**
   * @param string $type
   * @param string $id
   *
   * @return \Civi\Osdi\RemoteObjectInterface
   * @throws EmptyResultException
   * @throws InvalidArgumentException
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
    }
  }

  public function fetchPersonById(string $id): OsdiPerson {
    return $this->fetchById('osdi:people', $id);
  }

  public function find(string $objectType, array $criteria): \Civi\Osdi\ResultCollection {
    $filterClauses = [];
    foreach ($criteria as $criterion) {
      if (count($criterion) !== 3) {
        throw new InvalidArgumentException("Incorrect parameter format for findPeople");
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
    $resultResource = $this->filter($endpoint, join(' and ', $filterClauses));
    return new \Civi\Osdi\ResultCollection($this, 'osdi:people', $resultResource);
  }

  private function filter(?HalLink $endpoint, string $query) {
    $href = $endpoint->getHref() . "?filter=$query";
    $endPointWithQuery = $this->linkify($href);
    return $endPointWithQuery->get();
  }

  public function save(RemoteObjectInterface $osdiObject): RemoteObjectInterface {
    $type = $osdiObject->getType();
    $saveParams = $osdiObject->getAllAltered();
    if ($id = $osdiObject->getId()) {
      try {
        $result = $this->updateObjectOnRemoteSystem($type, $id, $saveParams);
      }
      catch (\Throwable $e) {
        $result = $this->createObjectOnRemoteSystem($type, $saveParams, $osdiObject);
      }
    }
    else {
      $result = $this->createObjectOnRemoteSystem($type, $saveParams, $osdiObject);
    }
    return $this->makeOsdiObject($type, $result);
  }

  public function savePerson(RemoteObjectInterface $remotePerson): OsdiPerson {
    return $this->save($remotePerson);
  }

  public function delete(RemoteObjectInterface $osdiObject) {
    $endpoint = $this->linkify($osdiObject->getOwnUrl($this));
    return $endpoint->delete();
  }

  public function getPeopleUrl() {
    return $this->constructUrlFor('osdi:people', NULL);
  }

  private function getRootResource() {
    return $this->getClient()->root();
  }

  private function getEndpointFor(string $objectType, string $id = NULL): ?HalLink {
    try {
      return $this->linkify($this->constructUrlFor($objectType, $id));
    }
    catch (\Throwable $e) {
      throw new InvalidArgumentException('Cannot get endpoint for "%s"', $objectType);
    }
  }

  private function getPersonSignupHelperEndpoint(): ?HalLink {
    return $this->getEndpointFor('osdi:people');
    //$rootResource = $this->getRootResource();
    //return $rootResource->getFirstLink('osdi:person_signup_helper');
  }

  private function updateObjectOnRemoteSystem(string $type, string $id, array $saveParams) {
    $endpoint = $this->getEndpointFor($type, $id);
    return $endpoint->put([], ['body' => $saveParams]);
  }

  private function createObjectOnRemoteSystem(string $type, array $saveParams, ?RemoteObjectInterface $osdiObject) {
    if ('osdi:people' === $type) {
      return $this->createPersonOnRemoteSystem($saveParams);
    }
    if ('osdi:taggings' === $type) {
      return $this->createTaggingOnRemoteSystem($saveParams, $osdiObject);
    }
    $endpoint = $this->getEndpointFor($type);
    return $endpoint->post([], ['body' => $saveParams]);
  }

  private function createPersonOnRemoteSystem(array $personParams) {
    $endpoint = $this->getPersonSignupHelperEndpoint();
    return $endpoint->post([], ['body' => ['person' => $personParams]]);
  }

  private function createTaggingOnRemoteSystem(array $saveParams, OsdiTagging $tagging) {
    $url = $tagging->getTag()->getOwnUrl($this) . '/taggings';
    $endpoint = $this->linkify($url);
    return $endpoint->post([], ['body' => $saveParams]);
  }

  public function getClient(): HalClientInterface {
    if (empty($this->client)) {
      $entryPoint = $this->systemProfile ? $this->systemProfile->entry_point : '';
      $this->client = new HalClient($entryPoint);
    }
    if (empty($this->client->getHeader('OSDI-API-Token'))) {
      $apiToken = $this->systemProfile ? $this->systemProfile->api_token : '';
      if ($apiToken) {
        $this->client = $this->client->withHeader('OSDI-API-Token', $apiToken);
      }
    }
    return $this->client;
  }

  public function constructUrlFor(string $objectType, string $id = NULL): ?string {
    $urlMap = [
      'osdi:people' => 'https://actionnetwork.org/api/v2/people',
      'osdi:tags' => 'https://actionnetwork.org/api/v2/tags',
      'osdi:taggings' => 'https://actionnetwork.org/api/v2/tags',
    ];
    if ($url = $urlMap[$objectType] ?? NULL) {
      return $url . ($id ? "/$id" : '');
    }
    throw new EmptyResultException('Could not find url for "%s"', $objectType);
  }

  private function linkify(string $url): HalLink {
    return new HalLink($this->getClient(), $url);
  }

}
