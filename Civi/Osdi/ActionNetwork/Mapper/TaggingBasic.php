<?php

namespace Civi\Osdi\ActionNetwork\Mapper;

use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Result\Map as MapResult;
use Civi\Osdi\SingleSyncerInterface;

class TaggingBasic implements \Civi\Osdi\MapperInterface {

  protected ?SingleSyncerInterface $personSyncer = NULL;

  protected ?SingleSyncerInterface $tagSyncer = NULL;

  protected ?SingleSyncerInterface $taggingSyncer;

  public function __construct(?SingleSyncerInterface $taggingSyncer = NULL) {
    $this->taggingSyncer = $taggingSyncer;
  }

  public function mapOneWay(LocalRemotePair $taggingPair): MapResult {
    $result = new MapResult();
    /** @var \Civi\Osdi\LocalObject\TaggingBasic $originTagging */
    $originTagging = $taggingPair->getOriginObject();
    $errorMessage = 'Person and Tag must exist on both systems before '
      . 'Tagging can be mapped';

    $personSyncer = $this->getPersonSyncer();
    $personPair = $personSyncer
      ->toLocalRemotePair()
      ->setOrigin($taggingPair->getOrigin())
      ->setOriginObject($originTagging->getPerson());
    $personMatchResult = $personSyncer->fetchOldOrFindNewMatch($personPair);

    switch ($personMatchResult->getStatusCode()) {
      case $personMatchResult::FOUND_NEW_MATCH:
        //$this->personSyncer->saveMatch
      case $personMatchResult::FETCHED_SAVED_MATCH:
        $targetPerson = $personPair->getTargetObject();
        break;

      default:
        $result->setStatusCode($result::ERROR)->setMessage($errorMessage);
        return $result;
    }

    $tagSyncer = $this->getTagSyncer();
    $tagPair = $tagSyncer
      ->toLocalRemotePair()
      ->setOrigin($taggingPair->getOrigin())
      ->setOriginObject($originTagging->getTag());
    $tagMatchResult = $tagSyncer->fetchOldOrFindNewMatch($tagPair);

    switch ($tagMatchResult->getStatusCode()) {
      case $tagMatchResult::FOUND_NEW_MATCH:
        //$this->tagSyncer->saveMatch()
      case $tagMatchResult::FETCHED_SAVED_MATCH:
        $targetTag = $tagPair->getTargetObject();
        break;

      default:
        $result->setStatusCode($result::ERROR)->setMessage($errorMessage);
        return $result;
    }

    $targetTagging = $taggingPair->isOriginLocal()
      ? $this->getTaggingSyncer()->makeRemoteObject()
      : $this->getTaggingSyncer()->makeLocalObject();
    $targetTagging->setPerson($targetPerson);
    $targetTagging->setTag($targetTag);
    $taggingPair->setTargetObject($targetTagging);

    $result->setStatusCode($result::SUCCESS);
    return $result;
  }

  public function getPersonSyncer(): ?SingleSyncerInterface {
    if (empty($this->personSyncer)) {
      $this->personSyncer = $this->taggingSyncer->getPersonSyncer();
    }
    return $this->personSyncer;
  }

  public function setPersonSyncer(?SingleSyncerInterface $personSyncer): TaggingBasic {
    $this->personSyncer = $personSyncer;
    return $this;
  }

  public function getTagSyncer(): ?SingleSyncerInterface {
    if (empty($this->tagSyncer)) {
      $this->tagSyncer = $this->taggingSyncer->getTagSyncer();
    }
    return $this->tagSyncer;
  }

  public function setTagSyncer(?SingleSyncerInterface $tagSyncer): TaggingBasic {
    $this->tagSyncer = $tagSyncer;
    return $this;
  }

  public function getTaggingSyncer(): ?SingleSyncerInterface {
    return $this->taggingSyncer;
  }

  public function setTaggingSyncer(?SingleSyncerInterface $taggingSyncer): TaggingBasic {
    $this->taggingSyncer = $taggingSyncer;
    return $this;
  }

}
