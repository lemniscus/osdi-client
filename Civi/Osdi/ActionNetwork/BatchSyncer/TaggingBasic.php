<?php

namespace Civi\Osdi\ActionNetwork\BatchSyncer;

use Civi\Api4\EntityTag;
use Civi\Osdi\BatchSyncerInterface;
use Civi\Osdi\Exception\InvalidOperationException;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\SingleSyncerInterface;

class TaggingBasic implements BatchSyncerInterface {

  private ?SingleSyncerInterface $singleSyncer;

  public function __construct(SingleSyncerInterface $singleSyncer = NULL) {
    $this->singleSyncer = $singleSyncer;
  }

  public function batchSyncFromRemote(): ?int {
    /** @var \Civi\Osdi\ActionNetwork\SingleSyncer\TaggingBasic $taggingSingleSyncer */
    $taggingSingleSyncer = $this->singleSyncer;
    $tagSingleSyncer = $taggingSingleSyncer->getTagSyncer();
    $system = $taggingSingleSyncer->getRemoteSystem();

    $remoteTagCollection = $system->findAll('osdi:tags');
    foreach ($remoteTagCollection as $remoteTag) {
      $tagPair = $tagSingleSyncer->toLocalRemotePair(NULL, $remoteTag);
      $tagPair->setOrigin(LocalRemotePair::ORIGIN_REMOTE);
      $tagSingleSyncer->fetchOldOrFindAndSaveNewMatch($tagPair);
      $localTag = $tagPair->getLocalObject();
      if (empty($localTag)) {
        continue;
      }
      $localTagId = $localTag->getId();
      if (!empty($localTagId)) {
        $syncableTags[$localTagId] = $remoteTag;
      }
    }

    $totalTaggingCount = 0;

    foreach ($syncableTags as $localTagId => $remoteTag) {
      $whiteList = [];
      $remoteTaggingCollection = $remoteTag->getTaggings();

      foreach ($remoteTaggingCollection as $remoteTagging) {
        $taggingPair = $taggingSingleSyncer
          ->toLocalRemotePair(NULL, $remoteTagging)
          ->setOrigin(LocalRemotePair::ORIGIN_REMOTE);

        $writeResult = $taggingSingleSyncer->oneWayMapAndWrite($taggingPair);
        if (!$writeResult->isError()) {
          $whiteList[] = $taggingPair->getLocalObject()->getId();
        }
      }

      $totalTaggingCount += count($whiteList);

      $allLocalTaggingIds = EntityTag::get(FALSE)
        ->addWhere('entity_table', '=', 'civicrm_contact')
        ->addWhere('tag_id', '=', $localTagId)
        ->addSelect('id')
        ->execute()->column('id');

      $localIdsToBeDeleted = array_diff($allLocalTaggingIds, $whiteList);

      if ($localIdsToBeDeleted) {
        EntityTag::delete(FALSE)
          ->addWhere('id', 'IN', $localIdsToBeDeleted)
          ->execute();
      }
    }

    return $totalTaggingCount;
  }

  public function batchSyncFromLocal(): ?int {
    throw new InvalidOperationException('Not implemented');
  }

}
