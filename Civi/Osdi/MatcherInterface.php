<?php

namespace Civi\Osdi;

use Civi\Osdi\Result\MatchResult as MatchResult;

interface MatcherInterface {

  public function tryToFindMatchFor(LocalRemotePair $pair): MatchResult;

}
