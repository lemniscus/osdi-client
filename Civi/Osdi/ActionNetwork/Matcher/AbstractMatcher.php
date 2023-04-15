<?php

namespace Civi\Osdi\ActionNetwork\Matcher;

use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Result\MatchResult as MatchResult;

abstract class AbstractMatcher implements \Civi\Osdi\MatcherInterface {

  public function tryToFindMatchFor(LocalRemotePair $pair): MatchResult {
    try {
      $result = $pair->isOriginLocal()
        ? $this->tryToFindMatchForLocalObject($pair)
        : $this->tryToFindMatchForRemoteObject($pair);
    }
    catch (\Throwable $e) {
      $origin = $pair->isOriginLocal() ?
        MatchResult::ORIGIN_LOCAL : MatchResult::ORIGIN_REMOTE;
      $result = new MatchResult($origin);
      $result->setStatusCode(MatchResult::ERROR_MISC);
      $result->setMessage($e->getMessage());
      $result->setContext($e);
    }
    $pair->getResultStack()->push($result);
    return $result;
  }

  abstract protected function tryToFindMatchForLocalObject(
    LocalRemotePair $pair): MatchResult;

  abstract protected function tryToFindMatchForRemoteObject(
    LocalRemotePair $pair): MatchResult;

}
