<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer;

use Civi\Osdi\ActionNetwork\Object\Tagging as OsdiTaggingObject;
use Civi\Osdi\ActionNetwork\RemoteSystem;
use Civi\Osdi\Exception\InvalidOperationException;
use Civi\Osdi\Container;
use Civi\Osdi\LocalObject\TaggingBasic as LocalTaggingObject;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\MapperInterface;
use Civi\Osdi\MatcherInterface;
use Civi\Osdi\Result\DeletionSync as DeletionSyncResult;
use Civi\Osdi\Result\MapAndWrite as MapAndWriteResult;
use Civi\Osdi\SingleSyncerInterface;

class TaggingBasic extends AbstractSingleSyncer {

  protected static array $savedMatches = [];

  protected ?SingleSyncerInterface $personSyncer = NULL;

  protected ?SingleSyncerInterface $tagSyncer = NULL;

  public function __construct(RemoteSystem $remoteSystem) {
    $this->remoteSystem = $remoteSystem;
  }

  public function setPersonSyncer(SingleSyncerInterface $personSyncer): self {
    $this->personSyncer = $personSyncer;
    return $this;
  }

  public function setTagSyncer(SingleSyncerInterface $tagSyncer): self {
    $this->tagSyncer = $tagSyncer;
    return $this;
  }

  public function getPersonSyncer(): SingleSyncerInterface {
    if (empty($this->personSyncer)) {
      $this->personSyncer = \Civi\OsdiClient::container()->make('SingleSyncer', 'Person', $this->getRemoteSystem());
    }
    return $this->personSyncer;
  }

  public function getTagSyncer(): SingleSyncerInterface {
    if (empty($this->tagSyncer)) {
      $this->tagSyncer = \Civi\OsdiClient::container()->make('SingleSyncer', 'Tag', $this->getRemoteSystem());
    }
    return $this->tagSyncer;
  }

  public function syncDeletion(LocalRemotePair $pair): DeletionSyncResult {
    $result = new DeletionSyncResult();

    $matchResult = $this->getMatcher()->tryToFindMatchFor($pair);
    $matchCode = $matchResult->getStatusCode();

    if ($matchResult::FOUND_MATCH === $matchCode) {
      $matchResult->getMatch()->delete();
      $result->setStatusCode($result::DELETED);
    }
    elseif ($matchResult::NO_MATCH === $matchCode) {
      $result->setStatusCode($result::NOTHING_TO_DELETE);
    }
    else {
      $result->setStatusCode($result::ERROR);
    }

    $pair->getResultStack()->push($result);
    return $result;
  }

  private function saveMatch(LocalRemotePair $pair): void {
    $syncProfileId = self::getSyncProfile()['id'] ?? 'null';
    $localId = $pair->getLocalObject()->getId();
    $remoteId = $pair->getRemoteObject()->getId();

    self::$savedMatches[$syncProfileId][$pair::ORIGIN_LOCAL][$localId] = $pair;
    self::$savedMatches[$syncProfileId][$pair::ORIGIN_REMOTE][$remoteId] = $pair;
  }

  public function getSavedMatch(LocalRemotePair $pair): ?LocalRemotePair {
    $profileId = $this->getSyncProfile()['id'] ?? 'null';
    if (empty($objectId = $pair->getOriginObject()->getId())) {
      return NULL;
    }
    return self::$savedMatches[$profileId][$pair->getOrigin()][$objectId] ?? NULL;
  }

  public function getMapper(): MapperInterface {
    if (empty($this->mapper)) {
      $this->mapper = \Civi\OsdiClient::container()->make('Mapper', 'Tagging', $this);
    }
    return $this->mapper;
  }

  public function getMatcher(): MatcherInterface {
    if (empty($this->matcher)) {
      $this->matcher = \Civi\OsdiClient::container()->make('Matcher', 'Tagging', $this);
    }
    return $this->matcher;
  }

  protected function getLocalObjectClass(): string {
    return LocalTaggingObject::class;
  }

  protected function getRemoteObjectClass(): string {
    return OsdiTaggingObject::class;
  }

  public function makeLocalObject($id = NULL): LocalTaggingObject {
    return new LocalTaggingObject($id);
  }

  public function makeRemoteObject($id = NULL): OsdiTaggingObject {
    $tagging = new OsdiTaggingObject($this->getRemoteSystem());
    if ($id) {
      $tagging->setId($id);
    }
    return $tagging;
  }

  public function oneWayMapAndWrite(LocalRemotePair $pair): MapAndWriteResult {
    if (!empty($t = $pair->getTargetObject())) {
      if (!empty($t->getId())) {
        throw new InvalidOperationException('%s does not allow updating '
        . 'already-persisted objects', __CLASS__);
      }
    }
    return parent::oneWayMapAndWrite($pair);
  }

}
