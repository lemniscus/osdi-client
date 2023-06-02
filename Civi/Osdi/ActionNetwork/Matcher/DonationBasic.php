<?php
namespace Civi\Osdi\ActionNetwork\Matcher;

use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Result\MatchResult as MatchResult;

class DonationBasic extends AbstractMatcher implements \Civi\Osdi\MatcherInterface {

  protected function tryToFindMatchForLocalObject(LocalRemotePair $pair): MatchResult {
    $result = new MatchResult($pair->getOrigin());

    $syncState = \Civi\Api4\OsdiDonationSyncState::get(FALSE)
    ->addWhere('contribution_id', '=', $pair->getLocalObject()->getId())
    // @todo limit by sync profile too?
    ->execute()->first();

    if ($syncState) {
      // @todo use Container
      $remoteClass = $pair->getRemoteClass();
      /** @var \Civi\Osdi\ActionNetwork\Object\Donation */
      $remoteObject = $remoteClass::fromId($syncState['remote_donation_id']);
      $result->setRemoteObject($remoteObject);
      $result->setStatusCode($result::FOUND_MATCH);
    }
    else {
      $result->setStatusCode($result::NO_MATCH);
    }
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
      /** @var \Civi\Osdi\LocalObject\DonationBasic */
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

