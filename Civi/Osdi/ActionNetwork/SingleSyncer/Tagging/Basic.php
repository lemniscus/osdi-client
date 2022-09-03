<?php

namespace Civi\Osdi\ActionNetwork\SingleSyncer\Tagging;

use Civi\Osdi\ActionNetwork\Object\Tagging as OsdiTaggingObject;
use Civi\Osdi\ActionNetwork\RemoteSystem;
use Civi\Osdi\ActionNetwork\SingleSyncer\AbstractSingleSyncer;
use Civi\Osdi\ActionNetwork\SingleSyncer\Person as PersonSyncer;
use Civi\Osdi\ActionNetwork\SingleSyncer\Tag\Basic as TagSyncer;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\Exception\InvalidOperationException;
use Civi\Osdi\LocalObject\Tagging as LocalTaggingObject;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Result\MapAndWrite as MapAndWriteResult;
use Civi\Osdi\Result\Sync;
use Civi\Osdi\SingleSyncerInterface;

class Basic extends AbstractSingleSyncer {

  protected static array $savedMatches = [];

  protected ?SingleSyncerInterface $personSyncer = NULL;

  protected ?SingleSyncerInterface $tagSyncer = NULL;

  const inputTypeActionNetworkTaggingObject = 'ActionNetwork:Tagging:Object';
  const inputTypeLocalEntityTagId = 'Local:EntityTag:Id';

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
      $personSyncerClass = $this->getSyncProfile()['SingleSyncer']['Person'];
      $this->personSyncer = new $personSyncerClass($this->getRemoteSystem());
    }
    return $this->personSyncer;
  }

  public function getTagSyncer(): SingleSyncerInterface {
    if (empty($this->tagSyncer)) {
      $tagSyncerClass = $this->getSyncProfile()['SingleSyncer']['Tag'];
      $this->tagSyncer = new $tagSyncerClass($this->getRemoteSystem());
    }
    return $this->tagSyncer;
  }

  private function oneWaySyncLocalById($id): Sync {
    try {
      $localObject = \Civi\Api4\EntityTag::get(FALSE)
        ->addWhere('id', '=', $id)
        ->addWhere('entity_table', '=', 'civicrm_contact')
        ->execute()->single();
    }
    catch (\API_Exception $exception) {
      return new Sync(
        NULL,
        NULL,
        Sync::ERROR,
        "Failed to retrieve local tag id '$id'", NULL,
        $exception
      );
    }

    $result = $this->getPersonSyncer()->getOrCreateMatchingObject(
      PersonSyncer::inputTypeLocalContactId, $localObject['entity_id']);

    if ($result->isError()) {
      return $result;
    }
    /** @var \Civi\Osdi\ActionNetwork\Object\Person $remotePerson */
    $remotePerson = $result->getRemoteObject();

    $result = $this->getTagSyncer()->getOrCreateMatch(
      TagSyncer::inputTypeLocalTagId, $localObject['tag_id']);

    if ($result->isError()) {
      return $result;
    }
    /** @var \Civi\Osdi\ActionNetwork\Object\Tag $remoteTag */
    $remoteTag = $result->getRemoteObject();

    $system = $this->getRemoteSystem();

    $draftRemoteObject = new OsdiTaggingObject(NULL, NULL);
    $draftRemoteObject->setPerson($remotePerson, $system);
    $draftRemoteObject->setTag($remoteTag, $system);

    $saveResult = $system->trySave($draftRemoteObject);
    $remoteObject = $saveResult->getReturnedObject();

    $this->logSyncLocalEntityTag($saveResult, $id);

    if ($saveResult->isError()) {
      return new Sync(
        $localObject,
        $remoteObject,
        Sync::ERROR,
        $saveResult->getMessage(), NULL,
        $saveResult->getContext()
      );
    }

    $this->saveMatch($localObject, $remoteObject);

    return new Sync(
      $localObject,
      $remoteObject,
      Sync::SUCCESS, NULL, NULL,
    );
  }

  private function oneWaySyncRemoteObject(OsdiTaggingObject $remoteObject): Sync {

    $remotePerson = $remoteObject->getPerson();
    $remoteTag = $remoteObject->getTag();

    if (empty($remotePerson) or empty($remoteTag)) {
      throw new InvalidArgumentException('Invalid remote tagging object supplied to '
        . __CLASS__ . '::' . __FUNCTION__);
    }

    $result = $this->getPersonSyncer()->getOrCreateMatchingObject(
      PersonSyncer::inputTypeActionNetworkPersonObject, $remotePerson);

    if ($result->isError()) {
      return $result;
    }
    $localContact = $result->getLocalObject();

    $result = $this->getTagSyncer()->getOrCreateMatch(
      TagSyncer::inputTypeActionNetworkTagObject, $remoteTag);

    if ($result->isError()) {
      return $result;
    }
    $localTag = $result->getLocalObject();

    $localObject = \Civi\Api4\EntityTag::create(FALSE)
      ->addValue('entity_table', 'civicrm_contact')
      ->addValue('entity_id', $localContact['id'])
      ->addValue('tag_id', $localTag['id'])
      ->execute()->single();

    \Civi::log()->debug(
      "OSDI sync attempt: remote tagging id" . $remoteObject->getId() . ": success"
    );

    $this->saveMatch($localObject, $remoteObject);

    return new Sync(
      $localObject,
      $remoteObject,
      Sync::SUCCESS, NULL, NULL,
    );
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
