<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer;

use Civi\Osdi\LocalObjectInterface;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\MapperInterface;
use Civi\Osdi\MatcherInterface;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\Result\FetchOldOrFindNewMatch as OldOrNewMatchResult;
use Civi\Osdi\SingleSyncerInterface;
use Civi\Osdi\Util;
use Civi\OsdiClient;

class TagBasic extends AbstractSingleSyncer implements SingleSyncerInterface {

  protected ?array $matchArray = NULL;

  public function __construct(RemoteSystemInterface $remoteSystem) {
    $this->remoteSystem = $remoteSystem;
  }

  public function getMapper(): MapperInterface {
    if (empty($this->mapper)) {
      $this->mapper = OsdiClient::container()
        ->make('Mapper', 'Tag', $this->getRemoteSystem());
    }
    return $this->mapper;
  }

  public function getMatcher(): MatcherInterface {
    if (empty($this->matcher)) {
      $this->matcher = OsdiClient::container()
        ->make('Matcher', 'Tag', $this->getRemoteSystem());
    }
    return $this->matcher;
  }

  public function getSavedMatch(
    LocalRemotePair $pair,
    int $syncProfileId = NULL
  ): ?LocalRemotePair {
    $side = $pair->isOriginLocal() ? 'local' : 'remote';
    $objectId = $pair->getOriginObject()->getId();
    $syncProfileId = $syncProfileId ?? OsdiClient::container()->getSyncProfileId();
    $savedMatches = self::getOrSetAllSavedMatches()[$syncProfileId] ?? [];
    return $savedMatches[$side][$objectId] ?? NULL;
  }

  protected function getLocalObjectClass(): string {
    return \Civi\Osdi\LocalObject\TagBasic::class;
  }

  protected function getRemoteObjectClass(): string {
    return \Civi\Osdi\ActionNetwork\Object\Tag::class;
  }

  public function makeLocalObject($id = NULL): LocalObjectInterface {
    return new \Civi\Osdi\LocalObject\TagBasic($id);
  }

  public function makeRemoteObject($id = NULL): RemoteObjectInterface {
    $tag = new \Civi\Osdi\ActionNetwork\Object\Tag($this->getRemoteSystem());
    if ($id) {
      $tag->setId($id);
    }
    return $tag;
  }

  public function saveMatch(LocalRemotePair $pair): LocalRemotePair {
    $localObject = $pair->getLocalObject();
    $remoteObject = $pair->getRemoteObject();
    $localId = $localObject->getId();
    $remoteId = $remoteObject->getId();
    $savedMatches = $this->getOrSetAllSavedMatches();
    $syncProfileId = OsdiClient::container()->getSyncProfileId();

    if ($oldMatchForLocal = $savedMatches[$syncProfileId]['local'][$localId] ?? NULL) {
      $oldMatchRemoteId = $oldMatchForLocal->getRemoteObject()->getId();
      unset($savedMatches[$syncProfileId]['remote'][$oldMatchRemoteId]);
      unset($savedMatches['persistable'][$syncProfileId][$localId]);
    }
    if ($oldMatchForRemote = $savedMatches[$syncProfileId]['remote'][$remoteId] ?? NULL) {
      $oldMatchLocalId = $oldMatchForRemote->getLocalObject()->getId();
      unset($savedMatches[$syncProfileId]['local'][$oldMatchLocalId]);
      unset($savedMatches['persistable'][$syncProfileId][$oldMatchLocalId]);
    }

    $pair = new LocalRemotePair($localObject, $remoteObject);
    $savedMatches[$syncProfileId]['local'][$localId] = $pair;
    $savedMatches[$syncProfileId]['remote'][$remoteId] = $pair;
    $savedMatches['persistable'][$syncProfileId][$localId] = $remoteId;

    $this->getOrSetAllSavedMatches($savedMatches);
    return $pair;
  }

  /**
   * @param \Civi\Osdi\LocalRemotePair $pair
   *
   * @return \Civi\Osdi\LocalRemotePair
   */
  protected function saveSyncStateIfNeeded(LocalRemotePair $pair) {
    if (empty($pair->getTargetObject()) || empty($pair->getTargetObject()->getId())) {
      return NULL;
    }

    $resultStack = $pair->getResultStack();
    if ($resultStack->lastIsError()) {
      return NULL;
    }

    $r = $resultStack->getLastOfType(OldOrNewMatchResult::class);
    if ($r->isStatus($r::FETCHED_SAVED_MATCH)) {
      return $pair;
    }

    return $this->saveMatch($pair);
  }

  protected function typeCheckLocalObject(LocalObjectInterface $object): \Civi\Osdi\LocalObject\TagBasic {
    Util::assertClass($object, \Civi\Osdi\LocalObject\TagBasic::class);
    /** @var \Civi\Osdi\LocalObject\TagBasic $object */
    return $object;
  }

  protected function typeCheckRemoteObject(RemoteObjectInterface $object): \Civi\Osdi\ActionNetwork\Object\Tag {
    Util::assertClass($object, \Civi\Osdi\ActionNetwork\Object\Tag::class);
    /** @var \Civi\Osdi\ActionNetwork\Object\Tag $object */
    return $object;
  }

  private function getOrSetAllSavedMatches($replacement = NULL): array {
    if (is_array($replacement)) {
      $this->matchArray = $replacement;
      \Civi::cache('long')
        ->set('osdi-client:tag-match', $this->matchArray['persistable']);
    }

    elseif (is_null($this->matchArray)) {
      $idArray = \Civi::cache('long')->get('osdi-client:tag-match', []);
      $this->matchArray = ['persistable' => $idArray];
      foreach ($idArray as $syncProfileId => $matches) {
        foreach ($matches as $localId => $remoteId) {
          $localObject = $this->makeLocalObject($localId);
          $remoteObject = $this->makeRemoteObject($remoteId);
          $pair = new LocalRemotePair($localObject, $remoteObject);
          $this->matchArray[$syncProfileId]['local'][$localId] = $pair;
          $this->matchArray[$syncProfileId]['remote'][$remoteId] = $pair;
        }
      }
    }
    return $this->matchArray;
  }

}
