<?php

namespace Civi\Osdi\ActionNetwork\Matcher;

use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\Result\MatchResult as MatchResult;
use Civi\OsdiClient;

class TagBasic extends AbstractMatcher implements \Civi\Osdi\MatcherInterface {

  protected RemoteSystemInterface $system;

  public function __construct(?RemoteSystemInterface $system = NULL) {
    $this->system = $system ?? OsdiClient::container()->getSingle(
      'RemoteSystem', 'ActionNetwork');
  }

  protected function tryToFindMatchForLocalObject(LocalRemotePair $pair): MatchResult {
    $result = new MatchResult(MatchResult::ORIGIN_LOCAL);

    $nameToMatch = $pair->getLocalObject()->loadOnce()->name->get();

    $allTags = $this->system->findAll('osdi:tags');
    foreach ($allTags as $remoteTag) {
      if ($nameToMatch === $remoteTag->name->get()) {
        $pair->setRemoteObject($remoteTag);
        $result->setMatch($remoteTag);
        $result->setStatusCode($result::FOUND_MATCH);
        return $result;
      }
    }

    $result->setStatusCode($result::NO_MATCH);
    return $result;
  }

  protected function tryToFindMatchForRemoteObject(LocalRemotePair $pair): MatchResult {
    $result = new MatchResult(MatchResult::ORIGIN_REMOTE);
    $localClass = $pair->getLocalClass();

    $civiApiTagGet = \Civi\Api4\Tag::get(FALSE)
      ->addWhere('name', '=', $pair->getRemoteObject()->name->get())
      ->execute();

    if ($civiApiTagGet->count()) {
      $tagArray = $civiApiTagGet->single();
      $localObject = new $localClass();
      $localObject->loadFromArray($tagArray);
      $result->setMatch($localObject);
      $result->setStatusCode($result::FOUND_MATCH);
      return $result;
    }

    $result->setStatusCode($result::NO_MATCH);
    return $result;
  }

}
