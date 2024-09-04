<?php

namespace Civi\Osdi\ActionNetwork\BatchSyncer;

use Civi\Api4\EntityTag;
use Civi\Api4\Tag;
use Civi\Osdi\BatchSyncerInterface;
use Civi\Osdi\Director;
use Civi\Osdi\Exception\InvalidOperationException;
use Civi\Osdi\LocalObjectInterface;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Logger;
use Civi\Osdi\SingleSyncerInterface;
use Civi\OsdiClient;

class TaggingBasic implements BatchSyncerInterface {

  private ?SingleSyncerInterface $singleSyncer;

  public function __construct(SingleSyncerInterface $singleSyncer = NULL) {
    $this->singleSyncer = $singleSyncer;
  }

  public function getRemoteSystem(): mixed {
    return OsdiClient::container()->getSingle('RemoteSystem', 'ActionNetwork');
  }

  public function getSingleSyncer(): ?SingleSyncerInterface {
    if (!$this->singleSyncer) {
      $this->singleSyncer = OsdiClient::container()->getSingle(
        'SingleSyncer', 'Tagging');
      $this->singleSyncer->setCaching(TRUE);
      $this->singleSyncer->getTagSyncer()->setCaching(TRUE);
      $this->singleSyncer->getPersonSyncer()->setCaching(TRUE);
    }
    return $this->singleSyncer;
  }

  public function batchSyncFromLocal(): ?string {
    if (!Director::acquireLock('Batch Civi->AN tagging sync')) {
      return NULL;
    }

    try {
      $totalTaggingCount = $this->batchSyncFromLocalWithOptionalDeletion(TRUE);

      Logger::logDebug("Tagging sync completed; $totalTaggingCount local taggings processed");
    }
    finally {
      Director::releaseLock();
    }

    return $totalTaggingCount;
  }

  /**
   * If no blocking process is running, run a batch tagging sync from Action
   * Network, adding any taggings that don't already exist in Civi, and deleting
   * any Civi taggings that aren't present on Action Network.
   *
   * @return string|null total number of Action Network taggings synced
   */
  public function batchSyncFromRemote(): ?string {
    if (!Director::acquireLock('Batch AN->Civi tagging sync')) {
      return NULL;
    }

    try {
      $totalTaggingCount = count(
        $this->batchSyncFromRemoteWithOptionalDeletion(TRUE));

      Logger::logDebug("Tagging sync completed; $totalTaggingCount remote taggings processed");
    }
    finally {
      Director::releaseLock();
    }

    return $totalTaggingCount;
  }

  public function batchTwoWayMirror(): ?int {

    if (!Director::acquireLock('Batch AN->Civi tagging two-way mirror')) {
      return NULL;
    }

    try {
      $remoteCount = $this->batchTwoWayMirrorRaw();
    }
    finally {
      Director::releaseLock();
    }
    return $remoteCount;
  }

  public function syncTaggingsFromLocalPerson(LocalObjectInterface $localPerson): bool {
    $contactId = $localPerson->getId();
    Logger::logDebug("Tagging sync requested for contact id $contactId");

    $personSyncer = \Civi\OsdiClient::container()->getSingle('SingleSyncer', 'Person',
      $this->singleSyncer->getRemoteSystem());

    $personPair = $personSyncer->toLocalRemotePair($localPerson);
    $personPair->setOrigin($personPair::ORIGIN_LOCAL);
    $matchResult = $personSyncer->fetchOldOrFindAndSaveNewMatch($personPair);
    if (!$matchResult->hasMatch()) {
      Logger::logDebug('Tagging sync aborted: no matching person on Action Network');
      return FALSE;
    }

    $entityTagArrays = EntityTag::get(FALSE)
      ->addWhere('entity_table', '=', 'civicrm_contact')
      ->addWhere('entity_id', '=', $contactId)
      ->execute();

    $localTaggings = [];
    foreach ($entityTagArrays as $entityTagArray) {
      $localTagging = \Civi\OsdiClient::container()->make('LocalObject', 'Tagging');
      $localTagging->loadFromArray($entityTagArray);
      $localTagName = $localTagging->getTagUsingCache()->name->get();
      $localTaggings[$localTagName] = $localTagging;
    }

    $remotePerson = $personPair->getRemoteObject();
    $remoteTaggingCollection = $remotePerson->getTaggings()->loadAll();
    $keptCount = $deletedCount = $copiedCount = 0;

    foreach ($remoteTaggingCollection as $remoteTagging) {
      $remoteTagName = $remoteTagging->getTagUsingCache()->name->get();
      if (array_key_exists($remoteTagName, $localTaggings)) {
        unset($localTaggings[$remoteTagName]);
        $keptCount++;
      }
      else {
        $remoteTagging->delete();
        $deletedCount++;
      }
    }

    $totalSuccess = TRUE;
    foreach ($localTaggings as $localTagging) {
      $taggingPair = $this->singleSyncer->toLocalRemotePair($localTagging);
      $taggingPair->setOrigin($taggingPair::ORIGIN_LOCAL);
      $result = $this->singleSyncer->oneWayMapAndWrite($taggingPair);
      $copiedCount++;
      $totalSuccess = $totalSuccess && $result->isError();
    }

    $email = $remotePerson->emailAddress->get();
    $withOrWithoutErrors = $totalSuccess ? '' : 'with some errors ';
    Logger::logDebug("Tagging sync finished {$withOrWithoutErrors}"
    . "for contact id $contactId, $email: "
    . "on AN person, $keptCount were kept, $deletedCount were deleted, "
    . "$copiedCount were copied from the Civi contact");

    return $totalSuccess;
  }

  /**
   * Find all syncable tags -- Action Network Tags which have Civi counterparts.
   * Find all local EntityTags for the eligible syncable Tags. Check which of
   * these Taggings already exist in Action Network, optionally deleting any
   * taggings that only exist on the Action Network side. Then copy from Civi to
   * Action Network local EntityTags that don't already exist in Action Network.
   */
  private function batchSyncFromLocalWithOptionalDeletion(bool $deleteNonMatching): int {
    $taggingSingleSyncer = $this->getSingleSyncer();
    $matchedTags = $this->getMatchedTags($taggingSingleSyncer);
    $eligibleLocalTagIds = $this->getEligibleLocalTagIds();
    $eligibleLocalTagIdsAsKeys = array_fill_keys($eligibleLocalTagIds, []);
    $eligibleMatchedTags = array_intersect_key($matchedTags, $eligibleLocalTagIdsAsKeys);
    Logger::logDebug(count($eligibleMatchedTags) . ' matching tags are eligible for syncing');

    $alreadySyncedLocalTaggings = [];

    foreach ($eligibleMatchedTags as $localTagId => $remoteTag) {
      $alreadySyncedLocalTaggings[$localTagId] = [];
      $tagName = $remoteTag->name->get();
      Logger::logDebug("Loading taggings for remote tag: $tagName");
      $remoteTaggingCollection = $remoteTag->getTaggings();
      $currentTagTaggingCount = $remoteTaggingCollection->loadAll()->rawCurrentCount();
      Logger::logDebug("Loaded $currentTagTaggingCount"
        . ' taggings from this remote tag; beginning sync');

      $lastLogTime = time();
      $processedCount = $existingCount = $deletedCount = $errorCount = 0;
      foreach ($remoteTaggingCollection as $remoteTagging) {
        if (time() - $lastLogTime > 14) {
          Logger::logDebug("still matching taggings for current remote tag: of "
            . "$currentTagTaggingCount, $processedCount done");
          $lastLogTime = time();
        }
        try {
          $taggingPair = $taggingSingleSyncer
            ->toLocalRemotePair(NULL, $remoteTagging)
            ->setOrigin(LocalRemotePair::ORIGIN_REMOTE);

          $matchResult = $taggingSingleSyncer->fetchOldOrFindAndSaveNewMatch($taggingPair);
          if ($matchResult->isError()) {
            $errorCount++;
          }
          elseif ($matchResult->hasMatch()) {
            $alreadySyncedLocalTaggings[$localTagId][] = $taggingPair->getLocalObject()->getId();
            $existingCount++;
          } elseif ($deleteNonMatching) {
            $deletedCount++;
            $taggingPair->getRemoteObject()->delete();
          }
          $processedCount++;
        }
        catch (\Throwable $e) {
          \Civi::log()->error('OSDI client: Error during batch Tagging '
            . 'sync from Action Network: ' . $e->getMessage());
          $errorCount++;
        }
      }
      Logger::logDebug("Successfully matched $existingCount existing taggings between Civi and Action Network, $errorCount errors, deleting $deletedCount which only existed on Action Network, for remote tag: $tagName");
    }

    $tagsToSyncWithEntityTagsToSkip =
      array_intersect_key($alreadySyncedLocalTaggings, $eligibleMatchedTags)
      + array_fill_keys(array_keys($eligibleMatchedTags), []);

    [
      $newFromLocalCount,
      $successFromLocalCount,
      $errorFromLocalCount,
    ] = $this->syncTaggingsFromLocalTags($tagsToSyncWithEntityTagsToSkip);

    Logger::logDebug("Tagging sync completed; $newFromLocalCount "
      . "local taggings synced into Action Network ($successFromLocalCount "
      . "successfully, $errorFromLocalCount errors)");

    return $existingCount + $newFromLocalCount;
  }

  /**
   * Find all syncable tags -- Action Network Tags which have Civi counterparts.
   * Find all Action Network Taggings for the syncable Tags. Copy all of these
   * Taggings into Civi, keeping track of which ones are new. Then optionally
   * delete any remaining Taggings in Civi for syncable Tags.
   */
  private function batchSyncFromRemoteWithOptionalDeletion(bool $deleteNonMatching): array {
    $taggingSingleSyncer = $this->getSingleSyncer();
    $syncableTags = $this->getMatchedTags($taggingSingleSyncer);

    $syncedLocalTaggings = [];

    foreach ($syncableTags as $localTagId => $remoteTag) {
      $syncedLocalTaggings[$localTagId] = [];
      $tagName = $remoteTag->name->get();
      Logger::logDebug("Loading taggings for remote tag: $tagName");
      $remoteTaggingCollection = $remoteTag->getTaggings();
      $currentTagTaggingCount = $remoteTaggingCollection->loadAll()->rawCurrentCount();
      Logger::logDebug("Loaded $currentTagTaggingCount"
        . ' taggings from this remote tag; beginning sync');

      $lastLogTime = time();
      $processedCount = $newCount = $errorCount = 0;
      foreach ($remoteTaggingCollection as $remoteTagging) {
        if (time() - $lastLogTime > 14) {
          Logger::logDebug("still syncing taggings for current remote tag: of "
            . "$currentTagTaggingCount, $processedCount done");
          $lastLogTime = time();
        }
        try {
          $taggingPair = $taggingSingleSyncer
            ->toLocalRemotePair(NULL, $remoteTagging)
            ->setOrigin(LocalRemotePair::ORIGIN_REMOTE);

          $writeResult = $taggingSingleSyncer->oneWayMapAndWrite($taggingPair);
          if ($writeResult->isError()) {
            $errorCount++;
          }
          else {
            $syncedLocalTaggings[$localTagId][] = $taggingPair->getLocalObject()->getId();
          }
          if ($writeResult->isStatus($writeResult::WROTE_NEW)) {
            $newCount++;
          }
          $processedCount++;
        }
        catch (\Throwable $e) {
          \Civi::log()->error('OSDI client: Error during batch Tagging '
          . 'sync from Action Network: ' . $e->getMessage());
          $errorCount++;
        }
      }
      Logger::logDebug("Successfully added $newCount new taggings to Civi, $errorCount errors, for remote tag: $tagName");

      if ($deleteNonMatching) {
        $this->deleteNonMatchingLocalTaggings(
          $localTagId, $syncedLocalTaggings[$localTagId]);
      }
    }

    return $syncedLocalTaggings;
  }

  private function batchTwoWayMirrorRaw(): int {
    Logger::logDebug('First mirroring taggings from Action Network into Civi');

    $entityTagIdsSyncedFromRemote =
      $this->batchSyncFromRemoteWithOptionalDeletion(FALSE);
    $remoteCount = array_sum(array_map('count', $entityTagIdsSyncedFromRemote));

    Logger::logDebug('Now mirroring taggings from Civi into Action Network');

    $eligibleLocalTagIds = $this->getEligibleLocalTagIds();
    $eligibleLocalTagIdsAsKeys = array_fill_keys($eligibleLocalTagIds, []);

    $tagsToSyncWithEntityTagsToSkip =
      array_intersect_key($entityTagIdsSyncedFromRemote, $eligibleLocalTagIdsAsKeys)
      + $eligibleLocalTagIdsAsKeys;

    [
      $newFromLocalCount,
      $successFromLocalCount,
      $errorFromLocalCount
    ] = $this->syncTaggingsFromLocalTags($tagsToSyncWithEntityTagsToSkip);

    Logger::logDebug("Tagging sync completed; $remoteCount remote taggings "
      . "synced into Civi of which some may have already existed; $newFromLocalCount "
      . "local taggings synced into Action Network ($successFromLocalCount "
      . "successfully, $errorFromLocalCount errors)");

    return $remoteCount;
  }

  private function deleteNonMatchingLocalTaggings($localTagId, array $syncedLocalTaggings): void {
    Logger::logDebug("Deleting non-matching taggings from Civi");
    $allEntityTags = EntityTag::get(FALSE)
      ->addWhere('entity_table', '=', 'civicrm_contact')
      ->addWhere('tag_id', '=', $localTagId)
      ->addSelect('id')
      ->execute();

    // // the following approach would result in a lot of additions to the sync queue
    //$allLocalEntityTagIds = $allEntityTags->column('id');
    //$localIdsToBeDeleted = array_diff($allLocalEntityTagIds, $whiteList);
    //if ($localIdsToBeDeleted) {
    //  EntityTag::delete(FALSE)
    //    ->addWhere('id', 'IN', $localIdsToBeDeleted)
    //    ->execute();
    //}

    // TODO this should not delete EntityTags for ineligible tags!
    $deletedCount = 0;
    foreach ($allEntityTags as $entityTag) {
      if (!in_array($entityTag['id'], $syncedLocalTaggings)) {
        $localTagging = \Civi\OsdiClient::container()->make('LocalObject', 'Tagging');
        $localTagging->loadFromArray($entityTag);
        $localTagging->delete();
        $deletedCount++;
      }
    }
    Logger::logDebug("Deleted $deletedCount non-matching taggings from Civi");
  }

  /**
   * @return array [$localTagId => $remoteTag, $localTagId => $remoteTag, ...]
   */
  private function getMatchedTags(?SingleSyncerInterface $taggingSingleSyncer): array {
    $system = $this->getRemoteSystem();
    $tagSingleSyncer = $taggingSingleSyncer->getTagSyncer();
    $remoteTagCollection = $system->findAll('osdi:tags');
    $syncableTags = [];

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
    Logger::logDebug($remoteTagCollection->rawCurrentCount() . ' tags found on '
      . 'AN, of which ' . count($syncableTags) . ' have counterparts in Civi');
    return $syncableTags;
  }

  private function syncTaggingsFromLocalTags(array $tagsToSyncWithEntityTagsToSkip): array {
    $alreadySyncedCount = $newFromLocalCount =
    $successFromLocalCount = $errorFromLocalCount = 0;

    foreach ($tagsToSyncWithEntityTagsToSkip as $tagId => $entityTagIdsToSkip) {
      $tagName = Tag::get(FALSE)->addWhere('id', '=', $tagId)
        ->execute()->first()['name'];

      $entityTagArrays = EntityTag::get(FALSE)
        ->addWhere('entity_table', '=', 'civicrm_contact')
        ->addWhere('tag_id', '=', $tagId)
        ->execute();

      $currentTagTotal = $entityTagArrays->count();
      $currentTagDone = 0;
      $lastLogTime = time();
      Logger::logDebug("Starting to sync local taggings for '$tagName'; $currentTagTotal to process");

      foreach ($entityTagArrays as $entityTagArray) {
        if (time() - $lastLogTime > 14) {
          Logger::logDebug("...still syncing taggings for local tag '$tagName': of "
            . "$currentTagTotal, $currentTagDone done");
          $lastLogTime = time();
        }
        $currentTagDone++;

        if (in_array($entityTagArray['id'], $entityTagIdsToSkip)) {
          $alreadySyncedCount++;
          continue;
        }
        $newFromLocalCount++;

        try {
          $localTagging = \Civi\OsdiClient::container()
            ->make('LocalObject', 'Tagging');
          $localTagging->loadFromArray($entityTagArray);
          $taggingPair = $this->singleSyncer->toLocalRemotePair($localTagging);
          $taggingPair->setOrigin($taggingPair::ORIGIN_LOCAL);
          $result = $this->singleSyncer->oneWayMapAndWrite($taggingPair);
          $result->isError() ? $errorFromLocalCount++ : $successFromLocalCount++;
          if ($result->isError()) {
            $m = $result->getMessage();
            $errorCodeAndMessage = $result->getStatusCode() . ($m ? "- $m": '');
            \Civi::log()->error(
              "Error syncing EntityTag id {$entityTagArray['id']}: $errorCodeAndMessage");
          }
        } catch (\Throwable $e) {
          \Civi::log()
            ->error('OSDI client: Error during batch Tagging sync from Civi',
              [
                'Message' => $e->getMessage(),
                'File' => $e->getFile(),
                'Line' => $e->getLine(),
              ]);
          $errorFromLocalCount++;
        }
      }
      Logger::logDebug("Finished syncing local taggings for '$tagName': $newFromLocalCount new to Action Network ($errorFromLocalCount errors), $alreadySyncedCount already in sync");
    }
    return [$newFromLocalCount, $successFromLocalCount, $errorFromLocalCount];
  }

  protected function getEligibleLocalTagIds(): array {
    return Tag::get(FALSE)
      ->addSelect('id')
      ->execute()
      ->column('id');
  }

}
