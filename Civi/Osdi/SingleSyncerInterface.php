<?php

namespace Civi\Osdi;

use Civi\Osdi\Result\FetchOldOrFindNewMatch as OldOrNewMatchResult;
use Civi\Osdi\Result\MapAndWrite as MapAndWriteResult;

interface SingleSyncerInterface {

  /**
   * The same as fetchOldOrFindNewMatch(), but if a new match is found, cache it
   * (if caching is implemented).
   *
   * Pushes its result onto the pair's result stack in addition to returning the
   * result.
   */
  public function fetchOldOrFindAndSaveNewMatch(LocalRemotePair $pair): OldOrNewMatchResult;

  /**
   * If this syncer implements match/sync state caching and a cached match
   * exists for the origin member of the given LocalRemotePair, return the
   * cached match. Otherwise, try to find a match for the origin object, most
   * likely using a Matcher class.
   *
   * Pushes its result onto the pair's result stack in addition to returning the
   * result.
   */
  public function fetchOldOrFindNewMatch(LocalRemotePair $pair): OldOrNewMatchResult;

  public function getMapper(): MapperInterface;

  public function getMatcher(): MatcherInterface;

  public function getRemoteSystem(): RemoteSystemInterface;

  public function makeLocalObject($id = NULL): LocalObjectInterface;

  public function makeRemoteObject($id = NULL): RemoteObjectInterface;

  /**
   * Check whether the given object is eligible to be synced (according to
   * criteria specified in implementing classes). If so, find a match for it
   * on the other system and, in some implementations, create a match if none
   * is found, then perform a one-way sync from the given object to its twin.
   *
   * @return \Civi\Osdi\LocalRemotePair
   *   containing the given object, a twin object on the other system if one was
   *   found/created, the direction of the attempted sync, results of the
   *   processes that it went through etc.
   */
  public function matchAndSyncIfEligible(CrudObjectInterface $originObject): LocalRemotePair;

  /**
   * Map $pair's origin object onto its target object, most likely using a
   * Mapper class. Persist the target object if it has been changed.
   *
   * Pushes its result onto the pair's result stack in addition to returning the result.
   */
  public function oneWayMapAndWrite(LocalRemotePair $pair): MapAndWriteResult;

  public function setMapper(?MapperInterface $mapper): self;

  public function setMatcher(?MatcherInterface $matcher): self;

  public function setRemoteSystem(RemoteSystemInterface $remoteSystem): self;

  /**
   * Package the given local and remote objects as a LocalRemotePair.
   */
  public function toLocalRemotePair(
    LocalObjectInterface $localObject = NULL,
    RemoteObjectInterface $remoteObject = NULL
  ): LocalRemotePair;

}
