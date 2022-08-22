<?php

namespace Civi\Osdi;

use Civi\Osdi\Result\MapAndWrite as MapAndWriteResult;

interface SingleSyncerInterface {

  /**
   * @param \Civi\Osdi\LocalObject\LocalObjectInterface|\Civi\Osdi\RemoteObjectInterface $originObject
   *
   * @return \Civi\Osdi\LocalRemotePair
   */
  public function matchAndSyncIfEligible($originObject): LocalRemotePair;

  public function oneWayMapAndWrite(LocalRemotePair $pair): MapAndWriteResult;

  public function getMapper(): MapperInterface;

  public function setMapper(MapperInterface $mapper): void;

  public function getMatcher(): MatcherInterface;

  public function setMatcher(MatcherInterface $matcher): void;

  public function getRemoteSystem(): RemoteSystemInterface;

  public function setRemoteSystem(RemoteSystemInterface $remoteSystem): void;

  public function getSyncProfile(): array;

  public function setSyncProfile(array $syncProfile): void;

}
