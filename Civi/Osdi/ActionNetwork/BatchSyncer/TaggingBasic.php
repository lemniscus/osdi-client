<?php

namespace Civi\Osdi\ActionNetwork\BatchSyncer;

use Civi\Api4\EntityTag;
use Civi\Osdi\BatchSyncerInterface;
use Civi\Osdi\Exception\InvalidOperationException;
use Civi\Osdi\Container;
use Civi\Osdi\LocalObjectInterface;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Logger;
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

    $processName = 'Batch AN->Civi tagging sync';
    if ($this->isBlockedByOtherProcess($processName)) {
      return NULL;
    }

    $totalTaggingCount = count($this->batchSyncFromRemoteWithOptionalDeletion(
      $system, $tagSingleSyncer, $taggingSingleSyncer, TRUE));

    Logger::logDebug("Tagging sync completed; $totalTaggingCount remote taggings processed");

    return $totalTaggingCount;
  }

  public function batchSyncFromLocal(): ?int {
    throw new InvalidOperationException('Not implemented');
  }

  public function batchTwoWayMirror(): ?int {
    /** @var \Civi\Osdi\ActionNetwork\SingleSyncer\TaggingBasic $taggingSingleSyncer */
    $taggingSingleSyncer = $this->singleSyncer;
    $tagSingleSyncer = $taggingSingleSyncer->getTagSyncer();
    $system = $taggingSingleSyncer->getRemoteSystem();

    $processName = 'Batch AN->Civi tagging two-way mirror';
    if ($this->isBlockedByOtherProcess($processName)) {
      return NULL;
    }

    $entityTagIdsSyncedFromRemote = $this->batchSyncFromRemoteWithOptionalDeletion(
      $system, $tagSingleSyncer, $taggingSingleSyncer, FALSE);
    $remoteCount = array_sum(array_map('count', $entityTagIdsSyncedFromRemote));

    $totalSuccessful = $newFromLocalCount = 0;

    foreach ($entityTagIdsSyncedFromRemote as $tagId => $syncedEntityTagIds) {
      $entityTagArrays = EntityTag::get(FALSE)
        ->addWhere('entity_table', '=', 'civicrm_contact')
        ->addWhere('tag_id', '=', $tagId)
        ->execute();

      foreach ($entityTagArrays as $entityTagArray) {
        if (in_array($entityTagArray['id'], $syncedEntityTagIds)) {
          continue;
        }
        $localTagging = \Civi\OsdiClient::container()->make('LocalObject', 'Tagging');
        $localTagging->loadFromArray($entityTagArray);
        $taggingPair = $this->singleSyncer->toLocalRemotePair($localTagging);
        $taggingPair->setOrigin($taggingPair::ORIGIN_LOCAL);
        $result = $this->singleSyncer->oneWayMapAndWrite($taggingPair);
        $newFromLocalCount++;
        $totalSuccessful += $result->isError() ? 0 : 1;
      }
    }

    Logger::logDebug("Tagging sync completed; $remoteCount remote taggings "
      . "synced into Civi of which some may have already existed; $newFromLocalCount "
      . "local taggings synced into Action Network ($totalSuccessful successfully)");

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
      $localTagName = $localTagging->getTag()->loadOnce()->name->get();
      $localTaggings[$localTagName] = $localTagging;
    }

    $remotePerson = $personPair->getRemoteObject();
    $remoteTaggingCollection = $remotePerson->getTaggings()->loadAll();
    $keptCount = $deletedCount = $copiedCount = 0;

    foreach ($remoteTaggingCollection as $remoteTagging) {
      $remoteTagName = $remoteTagging->getTag()->name->get();
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

  private function batchSyncFromRemoteWithOptionalDeletion(
    \Civi\Osdi\RemoteSystemInterface $system,
    SingleSyncerInterface $tagSingleSyncer,
    SingleSyncerInterface $taggingSingleSyncer,
    bool $deleteNonMatching
  ): array {
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
    Logger::logDebug($remoteTagCollection->rawCurrentCount() . ' tags found on '
      . 'AN, of which ' . count($syncableTags) . ' have counterparts in Civi');

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
          \Civi::log()->error('OSDI client: Error during batch Tagging sync from Action Network');
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

  private function isBlockedByOtherProcess(string $processName): bool {
    Logger::logDebug("$processName requested");

    if ($lastJobPid = $this->isLastProcessStillRunning()) {
      Logger::logDebug("Sync process ID $lastJobPid is still running; quitting new process");
      return TRUE;
    }
    Logger::logDebug("$processName process ID is " . getmypid());

    if (is_null(\Civi::settings()->get('osdiClient.syncJobEndTime'))) {
      Logger::logDebug('Last sync job did not finish successfully');
    }

    return FALSE;
  }

  private function isLastProcessStillRunning(): int {
    $lastJobPid = \Civi::settings()->get('osdiClient.syncJobProcessId');
    if ($lastJobPid && posix_getsid($lastJobPid) !== FALSE) {
      return $lastJobPid;
    }
    return FALSE;
  }

}
