<?php

namespace Civi\Osdi;

use Jsor\HalClient\HalClientInterface;
use Jsor\HalClient\HalResource;

interface RemoteSystemInterface {

  /**
   * Get the complete collection of the given type of object from the Remote
   * System.
   *
   * @param string $objectType e.g. 'osdi:people'
   */
  public function findAll(string $objectType): RemoteFindResult;

  public static function formatDateTime(int $unixTimeStamp);

  public function getClient(): HalClientInterface;

  public function delete(RemoteObjectInterface $osdiObject);

  public function getEntryPoint(): string;

  public function fetch(RemoteObjectInterface $osdiObject);

  public function find(string $objectType, array $criteria): RemoteFindResult;

  public function makeOsdiObject(
    string $type,
    ?HalResource $resource
  ): RemoteObjectInterface;

  public function save(RemoteObjectInterface $osdiObject): HalResource;

}
