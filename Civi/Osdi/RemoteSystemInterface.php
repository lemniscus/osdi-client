<?php

namespace Civi\Osdi;

use Jsor\HalClient\HalClientInterface;
use Jsor\HalClient\HalResource;

interface RemoteSystemInterface {

  public function getClient(): HalClientInterface;

  public function delete(RemoteObjectInterface $osdiObject);

  public function getEntryPoint(): string;

  public function fetch(RemoteObjectInterface $param);

  public function find(string $objectType, array $criteria): ResultCollection;

  public function makeOsdiObject(
    string $type,
    ?HalResource $resource,
    ?array $initData = NULL): RemoteObjectInterface;

  public function save(RemoteObjectInterface $osdiObject): HalResource;

  public function trySave(RemoteObjectInterface $objectToSave): SaveResult;

}
