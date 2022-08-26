<?php

namespace Civi\Osdi;

use Civi\Osdi\LocalObject\LocalObjectInterface;
use Civi\Osdi\Result\FetchOldOrFindNewMatch as OldOrNewMatchResult;
use Civi\Osdi\Result\MapAndWrite as MapAndWriteResult;

interface SingleSyncerInterface {

  /**
   * @param \Civi\Osdi\LocalObject\LocalObjectInterface|\Civi\Osdi\RemoteObjectInterface $originObject
   *
   * @return \Civi\Osdi\LocalRemotePair
   */
  public function matchAndSyncIfEligible($originObject): LocalRemotePair;

  /**
   * Pushes its result onto the pair's result stack in addition to returning the result.
   */
  public function oneWayMapAndWrite(LocalRemotePair $pair): MapAndWriteResult;

  public function getMapper(): MapperInterface;

  public function setMapper(?MapperInterface $mapper): self;

  public function getMatcher(): MatcherInterface;

  public function setMatcher(?MatcherInterface $matcher): self;

  public function getRemoteSystem(): RemoteSystemInterface;

  public function setRemoteSystem(RemoteSystemInterface $remoteSystem): self;

  public function getSyncProfile(): array;

  public function setSyncProfile(array $syncProfile): self;

  /**
   * Pushes its result onto the pair's result stack in addition to returning the result.
   */
  public function fetchOldOrFindNewMatch(LocalRemotePair $pair): OldOrNewMatchResult;

  public function makeLocalObject($id = NULL): LocalObjectInterface;

  public function makeRemoteObject($id = NULL): RemoteObjectInterface;

  public function toLocalRemotePair(
    LocalObjectInterface $localObject = NULL,
    RemoteObjectInterface $remoteObject = NULL
  ): LocalRemotePair;

}
