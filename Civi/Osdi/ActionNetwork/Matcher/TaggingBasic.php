<?php

namespace Civi\Osdi\ActionNetwork\Matcher;

use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Result\MatchResult as MatchResult;
use Civi\Osdi\SingleSyncerInterface;

class TaggingBasic extends AbstractMatcher implements \Civi\Osdi\MatcherInterface {

  protected SingleSyncerInterface $personSyncer;

  protected SingleSyncerInterface $taggingSyncer;

  public function __construct(
    SingleSyncerInterface $taggingSyncer
  ) {
    $this->taggingSyncer = $taggingSyncer;
  }

  public function tryToFindMatchForLocalObject(LocalRemotePair $taggingPair): MatchResult {
  }

  public function tryToFindMatchForRemoteObject(LocalRemotePair $pair): MatchResult {
    $result = new MatchResult($pair->getOrigin());
    return $result;
  }

}
