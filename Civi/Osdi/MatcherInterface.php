<?php

namespace Civi\Osdi;

use Civi\Osdi\Result\Match as MatchResult;

interface MatcherInterface {

  public function tryToFindMatchFor(LocalRemotePair $pair): MatchResult;

}
