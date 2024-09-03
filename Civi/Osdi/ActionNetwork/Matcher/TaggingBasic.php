<?php

namespace Civi\Osdi\ActionNetwork\Matcher;

use Civi\Api4\EntityTag;
use Civi\Osdi\CrudObjectInterface;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Result\MatchResult as MatchResult;
use Civi\Osdi\SingleSyncerInterface;
use Civi\OsdiClient;

class TaggingBasic extends AbstractMatcher implements \Civi\Osdi\MatcherInterface {

  protected ?SingleSyncerInterface $personSyncer = NULL;
  protected ?SingleSyncerInterface $tagSyncer = NULL;

  public function tryToFindMatchForLocalObject(LocalRemotePair $taggingPair): MatchResult {
    $result = new MatchResult(MatchResult::ORIGIN_LOCAL);

    $origin = $taggingPair->getOrigin();
    $originTagging = $taggingPair->getLocalObject();

    $targetPerson = $this->matchPerson($origin, $originTagging);
    if (is_null($targetPerson)) {
      return $result->setStatusCode($result::NO_MATCH);
    }

    $targetTag = $this->matchTag($origin, $originTagging);
    if (is_null($targetTag)) {
      return $result->setStatusCode($result::NO_MATCH);
    }

    $targetTaggings = $targetPerson->getTaggings();
    foreach ($targetTaggings as $targetTagging) {
      if ($targetTagging->getTagUsingCache()->getId() === $targetTag->getId()) {
        $result->setMatch($targetTagging);
        $taggingPair->setTargetObject($targetTagging);
        $result->setStatusCode($result::FOUND_MATCH);
        return $result;
      }
    }

    $result->setStatusCode($result::NO_MATCH);
    return $result;
  }

  public function tryToFindMatchForRemoteObject(LocalRemotePair $taggingPair): MatchResult {
    $result = new MatchResult(MatchResult::ORIGIN_REMOTE);

    $origin = $taggingPair->getOrigin();
    $originTagging = $taggingPair->getRemoteObject();

    $targetPerson = $this->matchPerson($origin, $originTagging);
    if (is_null($targetPerson)) {
      return $result->setStatusCode($result::NO_MATCH);
    }

    $targetTag = $this->matchTag($origin, $originTagging);
    if (is_null($targetTag)) {
      return $result->setStatusCode($result::NO_MATCH);
    }

    $civiApiTaggingGet = EntityTag::get(FALSE)
      ->setWhere([
          ['entity_table', '=', 'civicrm_contact'],
          ['entity_id', '=', $targetPerson->getId()],
          ['tag_id', '=', $targetTag->getId()],
      ])->execute();

    if (0 == $civiApiTaggingGet->count()) {
      return $result->setStatusCode($result::NO_MATCH);
    }

    $targetTagging = OsdiClient::container()->callStatic(
      'LocalObject',
      'Tagging',
      'fromArray',
      $civiApiTaggingGet->first()
    );

    $result->setMatch($targetTagging);
    $taggingPair->setTargetObject($targetTagging);
    $result->setStatusCode($result::FOUND_MATCH);
    return $result;
  }

  protected function matchPerson(
    string $origin,
    CrudObjectInterface $originTagging
  ): ?CrudObjectInterface {
    $personSyncer = $this->getPersonSyncer();
    $personPair = $personSyncer
      ->toLocalRemotePair()
      ->setOrigin($origin)
      ->setOriginObject($originTagging->getPerson());
    $personMatchResult = $personSyncer->fetchOldOrFindNewMatch($personPair);

    $c = $personMatchResult->getStatusCode();
    if ($c == $personMatchResult::FOUND_NEW_MATCH
      || $c == $personMatchResult::FETCHED_SAVED_MATCH) {
      return $personPair->getTargetObject();
    }
    return NULL;
  }

  protected function matchTag(
    string $origin,
    CrudObjectInterface $originTagging
  ): ?CrudObjectInterface {
    $tagSyncer = $this->getTagSyncer();
    $tagPair = $tagSyncer
      ->toLocalRemotePair()
      ->setOrigin($origin)
      ->setOriginObject($originTagging->getTagUsingCache());
    $tagMatchResult = $tagSyncer->fetchOldOrFindNewMatch($tagPair);

    $c = $tagMatchResult->getStatusCode();
    if ($c == $tagMatchResult::FOUND_NEW_MATCH
      || $c == $tagMatchResult::FETCHED_SAVED_MATCH) {
      return $tagPair->getTargetObject();
    }
    return NULL;
  }

  protected function getPersonSyncer(): SingleSyncerInterface {
    if (empty($this->personSyncer)) {
      $this->personSyncer = OsdiClient::container()->getSingle(
        'SingleSyncer', 'Person');
    }
    return $this->personSyncer;
  }

  protected function setPersonSyncer(SingleSyncerInterface $syncer): self {
    $this->personSyncer = $syncer;
    return $this;
  }

  protected function getTagSyncer(): SingleSyncerInterface {
    if (empty($this->tagSyncer)) {
      $this->tagSyncer = OsdiClient::container()->getSingle(
        'SingleSyncer', 'Tag');
    }
    return $this->tagSyncer;
  }

  protected function setTagSyncer(SingleSyncerInterface $syncer): self {
    $this->tagSyncer = $syncer;
    return $this;
  }

}
