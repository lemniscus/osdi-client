<?php

use Civi\Osdi\Exception\AmbiguousResultException;
use Civi\Osdi\Exception\EmptyResultException;
use CRM_Osdi_ExtensionUtil as E;
use Civi\Osdi\Generic\OsdiPerson;
use Jsor\HalClient\HalClient;
use Jsor\HalClient\HalLink;
use Jsor\HalClient\HalResource;

class CRM_OSDI_BAO_RemoteSystem extends CRM_OSDI_DAO_RemoteSystem {
  /**
   * @var HalClient
   */
  private $client;

  /**
   * @param string $id
   * @return OsdiPerson
   * @throws EmptyResultException|AmbiguousResultException
   */
  // moved to \Civi\Osdi\ActionNetwork\RemoteSystem
  public function findPersonById(string $id): OsdiPerson {
    if (empty($id)) throw new EmptyResultException();
    $endpoint = $this->getPersonEndpoint($id);
    $personResource = $endpoint->get();
    return $this->remotePersonFromOsdiResource($personResource);
  }

  // moved relevant parts to \Civi\Osdi\ActionNetwork\RemoteSystem
  public function savePerson(OsdiPerson $remotePerson): OsdiPerson {
    $personParams = [
      'given_name' => $remotePerson->getOriginal('first_name'),
      'family_name' => $remotePerson->getOriginal('last_name'),
      'email_addresses' => [
          ['address' => $remotePerson->getOriginal('email')]
      ]
    ];
    if ($id = $remotePerson->getOriginal('id')) {
      try {
        $result = $this->updatePerson($id, $personParams);
      } catch (EmptyResultException $e) {
        $result = $this->createPerson($personParams);
      }
    } else {
      $result = $this->createPerson($personParams);
    }
    return $this->remotePersonFromOsdiResource($result);
  }

  public function deletePerson($id) {
    try {
      $endpoint = $this->getPersonEndpoint($id);
    } catch (EmptyResultException $e) {}
    return $endpoint->delete();
  }

  // moved to \Civi\Osdi\RemoteSystem
  public function getClient(): HalClient {
    if (empty($this->client)) {
      $client = new HalClient($this->entry_point);
      $this->client = $client->withHeader('OSDI-API-Token', $this->api_token);
    }
    return $this->client;
  }

  /**
   * @return HalResource|\Psr\Http\Message\ResponseInterface
   */
  // TODO: move to \Civi\Osdi\Generic\RemoteSystem
  private function getRootResource() {
    return $this->getClient()->root();
  }

  // TODO: move to \Civi\Osdi\Generic\RemoteSystem
  private function getPeopleEndpoint(): ?HalLink {
    $rootResource = $this->getRootResource();
    return $rootResource->getFirstLink('osdi:people');
  }

  // moved relevant parts to \Civi\Osdi\ActionNetwork\RemoteSystem
  // TODO: move to \Civi\Osdi\Generic\RemoteSystem
  private function getPersonEndpoint($id = NULL): ?HalLink {
    $peopleEndpoint = $this->getPeopleEndpoint();
    if (empty($id)) return $peopleEndpoint;

    try {
      $findByIdResult = $this->filter($peopleEndpoint, "identifier eq '$id'");
      $foundPeopleLinks = $findByIdResult->getLink('osdi:people');
    } catch (Exception $e) {
      $foundPeopleLinks = [];
    }

    if (empty($foundPeopleLinks)) {
      $personEndpoint = $this->addPathComponent(
          $peopleEndpoint,
          OsdiPerson::idWithoutSystemName($id)
      );
      try {
        $personEndpoint->get();
        return $personEndpoint;
      } catch (Exception $e) {
        throw new EmptyResultException("Failed to find endpoint for id $id");
      }
    }
    if (count($foundPeopleLinks) > 1) throw new AmbiguousResultException();
    return $foundPeopleLinks[0];

    // THIS IS THE ACTION NETWORK SHORTCUT : MOVE TO ACTION NETWORK CHILD CLASS
    $idWithoutSystemName = OsdiPerson::idWithoutSystemName($id);
    return new HalLink($this->getClient(), $peopleEndpoint->getHref() . '/' . $idWithoutSystemName);
  }

  // moved to \Civi\Osdi\ActionNetwork\RemoteSystem
  private function getPersonSignupHelperEndpoint(): ?HalLink {
    $rootResource = $this->getRootResource();
    return $rootResource->getFirstLink('osdi:person_signup_helper');
  }

  /**
   * @param HalResource $resource
   * @return OsdiPerson
   */
  private function remotePersonFromOsdiResource(HalResource $resource): OsdiPerson {
    $id = $resource->getProperty('id') ?? $resource->getProperty('identifiers')[0];
    return new OsdiPerson(
        [
            'id' => $id,
            'first_name' => $resource->getProperty('given_name'),
            'last_name' => $resource->getProperty('family_name'),
            'email' => $resource->getProperty('email_addresses')[0]['address']
        ]
    );
  }

  private function addPathComponent(?HalLink $endpoint, string $newComponent) {
    $href = $endpoint->getHref() . "/$newComponent";
    return new HalLink($this->getClient(), $href);
  }

  // moved to \Civi\Osdi\ActionNetwork\RemoteSystem
  private function filter(?HalLink $endpoint, string $query) {
    $href = $endpoint->getHref() . "?filter=$query";
    $endPointWithQuery = new HalLink($this->getClient(), $href);
    return $endPointWithQuery->get();
  }

  /**
   * @param array $personParams
   * @return HalResource|\Psr\Http\Message\ResponseInterface
   */
  // moved to \Civi\Osdi\ActionNetwork\RemoteSystem
  private function createPerson(array $personParams) {
    $endpoint = $this->getPersonSignupHelperEndpoint();
    $result = $endpoint->post([], ['body' => ['person' => $personParams]]);
    return $result;
  }

  /**
   * @param $id
   * @param array $personParams
   * @return HalResource|\Psr\Http\Message\ResponseInterface
   * @throws AmbiguousResultException
   * @throws EmptyResultException
   */
  // moved to \Civi\Osdi\ActionNetwork\RemoteSystem
  // TODO: move to \Civi\Osdi\Generic\RemoteSystem
  private function updatePerson($id, array $personParams) {
    $endpoint = $this->getPersonEndpoint($id);
    $result = $endpoint->put([], ['body' => $personParams]);
    if (empty($result->getProperties())) {
      $result = $this->getPersonEndpoint($id)->get();
    }
    return $result;
  }

  // moved to \Civi\Osdi\ActionNetwork\RemoteSystem
  // TODO: move to \Civi\Osdi\Generic\RemoteSystem
  public function findPeople(array $criteria) {
    $filterClauses = [];
    foreach ($criteria as $criterion) {
      if (count($criterion) !== 3) throw new \Civi\Osdi\Exception\InvalidArgumentException("Incorrect parameter format for findPeople");
      list($key, $operator, $value) = $criterion;
      if ($operator === '=') {
        $filterClauses[] = "$key eq '" . addslashes($value) . "'";
      } else {
        throw new \Civi\Osdi\Exception\InvalidArgumentException("Operator '$operator' is not implemented");
      }
    }
    $peopleEndpoint = $this->getPeopleEndpoint();
    $resultResource = $this->filter($peopleEndpoint, join(' and ', $filterClauses));
    return new \Civi\Osdi\ResultCollection('osdi:people', $resultResource);
  }

}
