<?php

namespace Civi\Osdi\Mock;

use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\Generic\OsdiPerson;
use Civi\Osdi\RemoteObjectInterfaceOLD;
use Civi\Osdi\ResultCollection;
use Jsor\HalClient\HalClient;
use Jsor\HalClient\HalResource;

class RemoteSystem implements \Civi\Osdi\RemoteSystemInterface {

  private $database = [];

  public function fetchPersonById(string $id) {
    if (!array_key_exists($id, $this->database['osdi:people'])) {
      throw new EmptyResultException('Could not find remote person with id "%s"', $id);
    }
    return $this->database['osdi:people'][$id];
  }

  public function makeOsdiPerson(?HalResource $resource, ?array $initData = NULL) {
    // TODO: Implement makeRemotePerson() method.
  }

  /**
   * @param string $objectType
   * @param array $criteria
   *
   * @return array
   * @throws \Civi\Osdi\Exception\InvalidArgumentException
   */
  public function find(string $objectType, array $criteria): ResultCollection {
    if ('osdi:people' === $objectType) {
      if ([['email', '=', 'testy@test.net']] === $criteria) {
        $osdiPersonArr = array_filter($this->database['osdi:people'], function ($person) use ($criteria) {
          /** @var \Civi\Osdi\RemoteObjectInterfaceOLD $person */
          $theirEmails = $person->getOriginal('email_addresses');
          foreach ((array) $theirEmails as $emailArr) {
            if ($emailArr['address'] === $criteria[0][2]) {
              return TRUE;
            }
          }
          return FALSE;
        });
        $pageResource = new HalResource(new HalClient(''), [], [], $osdiPersonArr);
        new ResultCollection($this, 'osdi:people', $pageResource);
      }
    }
  }

  public function save(\Civi\Osdi\RemoteObjectInterfaceOLD $osdiObject): HalResource {
    if ('osdi:people' === $osdiObject->getType()) {
      return $this->savePerson($osdiObject);
    }
  }

  public function getPeopleUrl(): string {
    return 'http://te.st/people';
  }

  public function savePerson(OsdiPerson $remotePerson): OsdiPerson {
    try {
      $remotePerson->setId($newId = microtime());
    }
    catch (\Exception $e) {
    }
    $client = new HalClient('');
    $mergedProperties = $remotePerson->getAllOriginal();
    foreach ($remotePerson->getAllAltered() as $fieldName => $alteredValue) {
      if ($remotePerson->isMultipleValueField($fieldName)) {
        $mergedProperties[$fieldName] = array_merge($mergedProperties[$fieldName] ?? [], $alteredValue);
      }
      else {
        $mergedProperties[$fieldName] = $alteredValue;
      }
    }
    $personResource = new HalResource($client, $mergedProperties);
    $newRemotePerson = new OsdiPerson($personResource);
    try {
      $newRemotePerson->setId($newId);
    }
    catch (\Exception $e) {
    }
    $this->database['osdi:people'][$newId] = $newRemotePerson;
    return $newRemotePerson;
  }

  public function delete(RemoteObjectInterfaceOLD $object) {
    unset($this->database['osdi:people'][$object->getId()]);
  }

  public function constructUrlFor(string $objectType, ?string $id = NULL) {
    // TODO: Implement getUrlFor() method.
  }

  public function makeOsdiObject(string $type, ?HalResource $resource, ?array $initData = NULL): RemoteObjectInterfaceOLD {
    // TODO: Implement makeOsdiObject() method.
  }

  public function getEntryPoint(): string {
    // TODO: Implement getEntryPoint() method.
  }

  public function trySave(RemoteObjectInterfaceOLD $objectBeingSaved): \Civi\Osdi\SaveResult {
    // TODO: Implement trySave() method.
  }

}
