<?php

namespace Civi\Osdi\ActionNetwork\Matcher\Tag;

use Civi\Osdi\ActionNetwork\RemoteSystem;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\Result\Match as MatchResult;

class Basic implements \Civi\Osdi\MatcherInterface {

  private RemoteSystem $system;

  public function __construct(RemoteSystemInterface $system) {
    /** @var \Civi\Osdi\ActionNetwork\RemoteSystem $system */
    $this->system = $system;
  }

  public function tryToFindMatchFor(LocalRemotePair $pair): MatchResult {
    $result = $pair->isOriginLocal()
      ? $this->tryToFindMatchForLocalObject($pair)
      : $this->tryToFindMatchForRemoteObject($pair);
    $pair->getResultStack()->push($result);
    return $result;
  }

  public function tryToFindMatchForLocalObject(LocalRemotePair $pair): MatchResult {
    $result = new MatchResult($pair->getOrigin());
    $result->setStatusCode($result::NO_MATCH);
    $result->setMessage('Finding tags on Action Network is not implemented');
    return $result;
  }

  public function tryToFindMatchForRemoteObject(LocalRemotePair $pair): MatchResult {
    $result = new MatchResult($pair->getOrigin());
    $localClass = $pair->getLocalClass();

    $civiApiTagGet = \Civi\Api4\Tag::get(FALSE)
      ->addWhere('name', '=', $pair->getRemoteObject()->name->get())
      ->execute();

    if ($civiApiTagGet->count()) {
      $tagArray = $civiApiTagGet->single();
      /** @var \Civi\Osdi\LocalObject\Tag $localObject */
      $localObject = new $localClass();
      $localObject->loadFromArray($tagArray);
      $result->setLocalObject($localObject);
      $result->setStatusCode($result::FOUND_MATCH);
      return $result;
    }

    $result->setStatusCode($result::NO_MATCH);
    return $result;
  }

}
