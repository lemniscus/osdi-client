<?php

namespace Civi\Osdi\ActionNetwork\Matcher;

use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Result\Match as MatchResult;

abstract class AbstractMatcher implements \Civi\Osdi\MatcherInterface {

  public function tryToFindMatchFor(LocalRemotePair $pair): MatchResult {
    $result = $pair->isOriginLocal()
      ? $this->tryToFindMatchForLocalObject($pair)
      : $this->tryToFindMatchForRemoteObject($pair);
    $pair->getResultStack()->push($result);
    return $result;
  }

  abstract protected function tryToFindMatchForLocalObject(
    LocalRemotePair $pair): MatchResult;

  abstract protected function tryToFindMatchForRemoteObject(
    LocalRemotePair $pair): MatchResult;

}
