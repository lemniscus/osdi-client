<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer\Person;

use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\PersonSyncState;
use Civi\Osdi\Result\Matched;
use Civi\Osdi\Result\Sync;
use Civi\Osdi\Util;

trait PersonLocalRemotePairTrait {

  protected function fillLocalRemotePairFromSyncState(
    LocalRemotePair &$pair,
    PersonSyncState $syncState
  ): bool {
    $pair->setPersonSyncState($syncState);

    if (empty($syncState->getContactId()) || empty($syncState->getRemotePersonId())) {
      return FALSE;
    }

    $localPerson = $pair->getLocalObject();
    $localPersonClass = $pair->getLocalClass();
    $remotePerson = $pair->getRemoteObject();
    $remotePersonClass = $pair->getRemoteClass();

    if (!is_null($localPerson)) {
      Util::assertClass($localPerson, $localPersonClass);
    }
    if (!is_null($remotePerson)) {
      Util::assertClass($remotePerson, $remotePersonClass);
    }

    try {
      $localObject = $localPerson ??
        (new $localPersonClass($syncState->getContactId()))->load();
      $remoteObject = $remotePerson ??
        call_user_func(
          [$remotePersonClass, 'loadFromId'],
          $syncState->getRemotePersonId(), $this->getRemoteSystem());
    }
    catch (InvalidArgumentException | EmptyResultException $e) {
      $syncState->delete();
    }

    if (!is_null($localObject) && !is_null($remoteObject)) {
      $pair->setLocalObject($localObject)
        ->setRemoteObject($remoteObject)
        ->setIsError(FALSE)
        ->setMessage('fetched saved match');
      return TRUE;
    }

    return FALSE;
  }

  protected function fillLocalRemotePairFromNewfoundMatch(
    Matched $matchResult,
    LocalRemotePair $pair
  ): LocalRemotePair {
    if (Matched::ORIGIN_LOCAL === $matchResult->getOrigin()) {
      $localObject = $matchResult->getOriginObject();
      $remoteObject = $matchResult->getMatch();
    }
    else {
      $localObject = $matchResult->getMatch();
      $remoteObject = $matchResult->getOriginObject();
    }

    $syncState = new PersonSyncState();
    $syncState->setContactId($localObject->loadOnce()->getId());
    $syncState->setRemotePersonId($remoteObject->getId());
    $syncState->setSyncProfileId($this->syncProfileId);

    return $pair->setLocalObject($localObject)
      ->setRemoteObject($remoteObject)
      ->setIsError(FALSE)
      ->setMessage('found new match with existing object')
      ->setPersonSyncState($syncState)
      ->setMatchResult($matchResult);
  }

  protected function fillLocalRemotePairFromSyncResult(
    Sync $syncResult,
    LocalRemotePair $pair
  ): LocalRemotePair {
    $pair->setLocalObject($syncResult->getLocalObject())
      ->setRemoteObject($syncResult->getRemoteObject())
      ->setIsError($syncResult->isError())
      ->setMessage($syncResult->isError()
        ? 'error creating matching object' : 'created matching object')
      ->setPersonSyncState($syncResult->getState())
      ->setSyncResult($syncResult);
    return $pair;
  }

}
