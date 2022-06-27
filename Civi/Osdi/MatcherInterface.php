<?php

namespace Civi\Osdi;

use Civi\Osdi\LocalObject\Person as LocalPerson;

interface MatcherInterface {

  public function tryToFindMatchForLocalContact(LocalPerson $localPerson): MatchResult;

  public function tryToFindMatchForRemotePerson(RemoteObjectInterface $remotePerson): MatchResult;

}
