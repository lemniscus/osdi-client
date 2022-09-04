<?php

namespace Civi\Osdi;

use Civi\Osdi\Result\Map as MapResult;

interface MapperInterface {

  public function mapOneWay(LocalRemotePair $pair): MapResult;

  /*public function mapRemoteToLocal(
    RemoteObjectInterface $remotePerson,
    LocalObjectInterface $localPerson = NULL): LocalObjectInterface;

  public function mapLocalToRemote(
    LocalObjectInterface $localPerson,
    RemoteObjectInterface $remotePerson = NULL): RemoteObjectInterface;*/

}
