<?php
namespace Civi\Osdi\ActionNetwork\Matcher\Donation;

use Civi\Osdi\ActionNetwork\Matcher\AbstractMatcher;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Result\MatchResult as MatchResult;
// use Civi\Osdi\ActionNetwork\RemoteFindResult;
// use Civi\Osdi\ActionNetwork\RemoteSystem;
// use Civi\Osdi\Exception\AmbiguousResultException;
// use Civi\Osdi\Exception\EmptyResultException;
// use Civi\Osdi\Exception\InvalidArgumentException;
// use Civi\Osdi\LocalObject\PersonBasic as LocalPerson;
// use Civi\Osdi\LocalObjectInterface;
// use Civi\Osdi\MatcherInterface;
// use Civi\Osdi\RemoteObjectInterface;

class Basic extends AbstractMatcher implements \Civi\Osdi\MatcherInterface {

  protected function tryToFindMatchForLocalObject(LocalRemotePair $pair): MatchResult {
    throw new \Exception( "code not written");
    $result = new MatchResult($pair->getOrigin());
    $result->setStatusCode($result::NO_MATCH);
    $result->setMessage('Finding tags on Action Network is not implemented');
    return $result;
  }

  protected function tryToFindMatchForRemoteObject(LocalRemotePair $pair): MatchResult {
    $result = new MatchResult($pair->getOrigin());

    $syncState = \Civi\Api4\OsdiDonationSyncState::get(FALSE)
    ->addWhere('remote_donation_id', '=', $pair->getRemoteObject()->getId())
    // @todo limit by sync profile too?
    ->execute()->first();

    if ($syncState) {
      $localClass = $pair->getLocalClass();
      /** @var \Civi\Osdi\LocalObject\Donation */
      $localObject = $localClass::fromId($syncState['contribution_id']);
      $result->setLocalObject($localObject);
      $result->setStatusCode($result::FOUND_MATCH);
    }
    else {
      $result->setStatusCode($result::NO_MATCH);
    }

    return $result;
  }

}

