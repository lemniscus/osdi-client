<?php

namespace Civi\Osdi;

interface RemoteSystemInterface {

  public function constructUrlFor(string $objectType, ?string $id = null);

  public function getPeopleUrl();

  public function makeOsdiObject(
      string $type,
      ?\Jsor\HalClient\HalResource $resource,
      ?array $initData = null): RemoteObjectInterface;

  public function fetchPersonById(string $id);

  public function find(string $objectType, array $criteria): \Civi\Osdi\ResultCollection;

  public function save(\Civi\Osdi\RemoteObjectInterface $osdiObject);

  public function delete(\Civi\Osdi\RemoteObjectInterface $osdiObject);

}