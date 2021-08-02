<?php

namespace Civi\Osdi;

interface RemoteSystemInterface {

  public function getEntryPoint(): string;

  public function constructUrlFor(string $objectType, ?string $id = NULL);

  public function getPeopleUrl();

  public function makeOsdiObject(
      string $type,
      ?\Jsor\HalClient\HalResource $resource,
      ?array $initData = NULL): RemoteObjectInterface;

  public function fetchPersonById(string $id);

  public function find(string $objectType, array $criteria): \Civi\Osdi\ResultCollection;

  public function save(\Civi\Osdi\RemoteObjectInterface $osdiObject);

  public function delete(\Civi\Osdi\RemoteObjectInterface $osdiObject);

}
