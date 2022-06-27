<?php

namespace Civi\Osdi;

use Civi\Osdi\LocalObject\LocalObjectInterface;

interface MapperInterface {

  public function mapRemoteToLocal(
    RemoteObjectInterface $remotePerson,
    LocalObjectInterface $localPerson = NULL): LocalObjectInterface;

  public function mapLocalToRemote(
    LocalObjectInterface $localPerson,
    RemoteObjectInterface $remotePerson = NULL): RemoteObjectInterface;

}
