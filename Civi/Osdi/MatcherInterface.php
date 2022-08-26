<?php

namespace Civi\Osdi;

use Civi\Osdi\Result\Matched as MatchResult;

interface MatcherInterface {

  public function tryToFindMatchFor(LocalRemotePair $pair): MatchResult;

  public function tryToFindMatchForLocalObject(LocalRemotePair $pair): MatchResult;

  public function tryToFindMatchForRemoteObject(LocalRemotePair $pair): MatchResult;

}
