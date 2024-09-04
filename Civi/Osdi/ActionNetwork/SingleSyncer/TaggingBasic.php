<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer;

use Civi\Osdi\ActionNetwork\Object\Tagging as OsdiTaggingObject;
use Civi\Osdi\Exception\InvalidOperationException;
use Civi\Osdi\LocalObject\TaggingBasic as LocalTaggingObject;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\Result\DeletionSync as DeletionSyncResult;
use Civi\Osdi\Result\MapAndWrite as MapAndWriteResult;
use Civi\Osdi\SingleSyncerInterface;
use Civi\OsdiClient;

class TaggingBasic extends AbstractSingleSyncer {

  protected static string $localType = 'Tagging';
  protected static string $remoteType = 'osdi:taggings';
  protected static array $savedMatches = [];
  protected ?SingleSyncerInterface $personSyncer = NULL;
  protected ?SingleSyncerInterface $tagSyncer = NULL;

  public function __construct(?RemoteSystemInterface $remoteSystem = NULL) {
    $this->remoteSystem = $remoteSystem ?? OsdiClient::container()->getSingle(
      'RemoteSystem', 'ActionNetwork');
    $this->registryKey = 'Tagging';
  }

  public function getPersonSyncer(): SingleSyncerInterface {
    if (empty($this->personSyncer)) {
      $this->personSyncer = OsdiClient::container()->getSingle('SingleSyncer', 'Person', $this->getRemoteSystem());
    }
    return $this->personSyncer;
  }

  public function setPersonSyncer(SingleSyncerInterface $personSyncer): self {
    $this->personSyncer = $personSyncer;
    return $this;
  }

  public function setTagSyncer(SingleSyncerInterface $tagSyncer): self {
    $this->tagSyncer = $tagSyncer;
    return $this;
  }

  public function getTagSyncer(): SingleSyncerInterface {
    if (empty($this->tagSyncer)) {
      $this->tagSyncer = OsdiClient::container()->getSingle('SingleSyncer', 'Tag', $this->getRemoteSystem());
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
      $result->setMessage('Error finding match: ' .
        $matchResult->getMessage() ?? $matchCode);
    }

    $pair->getResultStack()->push($result);
    return $result;
  }

  private function saveMatch(LocalRemotePair $pair): void {
    $syncProfileId = OsdiClient::container()->getSyncProfileId();
    $localId = $pair->getLocalObject()->getId();
    $remoteId = $pair->getRemoteObject()->getId();

    self::$savedMatches[$syncProfileId][$pair::ORIGIN_LOCAL][$localId] = $pair;
    self::$savedMatches[$syncProfileId][$pair::ORIGIN_REMOTE][$remoteId] = $pair;
  }

  public function getSavedMatch(LocalRemotePair $pair): ?LocalRemotePair {
    $profileId = OsdiClient::container()->getSyncProfileId();
    if (empty($objectId = $pair->getOriginObject()->getId())) {
      return NULL;
    }
    return self::$savedMatches[$profileId][$pair->getOrigin()][$objectId] ?? NULL;
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
