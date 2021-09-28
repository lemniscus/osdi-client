<?php


namespace Civi\Osdi\ActionNetwork;

use CRM_Osdi_ExtensionUtil as E;
use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\SaveResult;
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

  public function __construct(?\CRM_OSDI_BAO_SyncProfile $systemProfile, ?HalClientInterface $client = NULL) {
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
    $resultResource = $this->filter($endpoint, implode(' and ', $filterClauses));
    return new \Civi\Osdi\ActionNetwork\ResultCollection($this, $objectType, $resultResource);
  }

  private function filter(?HalLink $endpoint, string $query) {
    $href = $endpoint->getHref() . "?filter=$query";
    $endPointWithQuery = $this->linkify($href);
    return $endPointWithQuery->get();
  }

  public function save(RemoteObjectInterface $osdiObject): RemoteObjectInterface {
    $type = $osdiObject->getType();
    $saveParams = $osdiObject->getAllAltered();

    if ('osdi:people' === $type) {
      $saveParams = $this->subscribePersonDuringSaveByDefault($saveParams, $osdiObject);
    }
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

  public function delete(RemoteObjectInterface $osdiObject) {
    if ('osdi:people' === $osdiObject->getType()) {
      return $this->deletePerson($osdiObject);
    }
    $endpoint = $this->linkify($osdiObject->getOwnUrl($this));
    return $endpoint->delete();
  }

  public function getPeopleUrl() {
    return $this->constructUrlFor('osdi:people', NULL);
  }

  public function trySave(RemoteObjectInterface $objectToSave): SaveResult {
    $savedObject = $statusCode = $statusMessage = $context = NULL;

    if ('osdi:people' === $objectToSave->getType()) {
      [$statusCode, $statusMessage, $context] =
        $this->checkForEmailAddressConflict($objectToSave);
    }

    if ($statusCode !== SaveResult::ERROR) {
      try {
        $savedObject = $this->save($objectToSave);
        $statusCode = SaveResult::SUCCESS;
      }

      catch (InvalidArgumentException $e) {
        $statusCode = SaveResult::ERROR;
        $statusMessage = $e->getMessage();
        $context = [
          'object' => $objectToSave,
          'errorData' => $e->getErrorData(),
        ];
      }
    }

    if ($savedObject && !$savedObject->isSupersetOf($objectToSave, TRUE)) {
      $statusCode = SaveResult::ERROR;
      $statusMessage = E::ts(
        'Some or all of the %1 object could not be saved.',
        [1 => $objectToSave->getType()],
      );
      $context = [
        'sent' => $objectToSave->getAllAltered(),
        'response' => $savedObject->getAllOriginal(),
      ];
    }

    return new SaveResult($savedObject, $statusCode, $statusMessage, $context);
  }

  protected function checkForEmailAddressConflict(OsdiPerson $objectToSave): array {
    if ($id = $objectToSave->getId()) {
      $newEmail = $objectToSave->getAltered('email_addresses')[0]['address'] ?? NULL;

      if ($newEmail) {
        $criteria = [['email', 'eq', $newEmail]];
        $peopleWithTheEmail = $this->find('osdi:people', $criteria);

        if ($peopleWithTheEmail->rawCurrentCount()) {
          if ($id !== $peopleWithTheEmail->rawFirst()->getId()) {
            $statusCode = SaveResult::ERROR;
            $statusMessage = E::ts('The person cannot be saved because '
              . 'there is a record on Action Network with a the same '
              . 'email address and a different ID.');
            $context = [
              'object' => $objectToSave,
              'conflictingObject' => $peopleWithTheEmail->rawFirst(),
            ];
          }
        }
      }
    }

    return [$statusCode ?? NULL, $statusMessage ?? NULL, $context ?? NULL];
  }

  /**
   * @return \Jsor\HalClient\HalResource|ResponseInterface
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

  /**
   * @return \Jsor\HalClient\HalLink|null
   * @throws InvalidArgumentException
   */
  private function getPersonSignupHelperEndpoint(): ?HalLink {
    return $this->getEndpointFor('osdi:people');
    //$rootResource = $this->getRootResource();
    //return $rootResource->getFirstLink('osdi:person_signup_helper');
  }

  /**
   * @param string $type
   * @param string $id
   * @param array $saveParams
   * @return \Jsor\HalClient\HalResource|ResponseInterface
   * @throws InvalidArgumentException
   */
  private function updateObjectOnRemoteSystem(string $type, string $id, array $saveParams) {
    $endpoint = $this->getEndpointFor($type, $id);
    return $endpoint->put([], ['body' => $saveParams]);
  }

  /**
   * @param string $type
   * @param array $saveParams
   * @param \Civi\Osdi\RemoteObjectInterface|null $osdiObject
   * @return \Jsor\HalClient\HalResource|ResponseInterface
   * @throws InvalidArgumentException|EmptyResultException
   */
  private function createObjectOnRemoteSystem(string $type, array $saveParams, ?RemoteObjectInterface $osdiObject) {
    if ('osdi:people' === $type) {
      return $this->createPersonOnRemoteSystem($saveParams);
    }
    if ('osdi:taggings' === $type) {
      /** @var \Civi\Osdi\ActionNetwork\OsdiTagging $osdiObject */
      return $this->createTaggingOnRemoteSystem($saveParams, $osdiObject);
    }
    $endpoint = $this->getEndpointFor($type);
    return $endpoint->post([], ['body' => $saveParams]);
  }

  /**
   * @param array $personParams
   * @return \Jsor\HalClient\HalResource|ResponseInterface
   * @throws InvalidArgumentException
   */
  private function createPersonOnRemoteSystem(array $personParams) {
    $endpoint = $this->getPersonSignupHelperEndpoint();
    return $endpoint->post([], ['body' => ['person' => $personParams]]);
  }

  /**
   * @param array $saveParams
   * @param OsdiTagging $tagging
   * @return \Jsor\HalClient\HalResource|ResponseInterface
   * @throws EmptyResultException
   */
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

  /**
   * @param string $objectType
   * @param string|null $id
   * @return string|null
   * @throws EmptyResultException
   */
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

  private function deletePerson(RemoteObjectInterface $osdiObject) {
    if (empty($id = $osdiObject->getId())) {
      throw new InvalidArgumentException(
      'Cannot "delete" (unsubscribe) Action Network person without an id');
    }

    $person = OsdiPerson::blank();
    $person->setId($id);

    return $this->save($person);
  }

  private function subscribePersonDuringSaveByDefault(array $saveParams, RemoteObjectInterface $osdiObject): array {
    if (!empty($saveParams['email_addresses'][0]['address'])) {
      if (empty($saveParams['email_addresses'][0]['status'])) {
        $saveParams['email_addresses'][0]['status'] = 'subscribed';
      }
    }
    if (!empty($saveParams['phone_numbers'][0]['number'])) {
      if (empty($saveParams['phone_numbers'][0]['status'])) {
        $saveParams['phone_numbers'][0]['status'] = 'subscribed';
      }
    }
    if (empty($saveParams['email_addresses'][0]['status'])
      && empty($saveParams['phone_numbers'][0]['status'])) {
      if (!empty($osdiObject->get('email_addresses')[0]['address'])) {
        $saveParams['email_addresses'][0]['status'] = 'subscribed';
      }
      elseif (!empty($osdiObject->get('phone_numbers')[0]['number'])) {
        $saveParams['phone_numbers'][0]['status'] = 'subscribed';
      }
      else {
        $saveParams['email_addresses'][0]['status'] = 'subscribed';
      }
    }
    return $saveParams;
  }

}
