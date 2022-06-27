<?php

namespace Civi\Osdi\ActionNetwork\Syncer;

use Civi\Osdi\ActionNetwork\Mapper\Person;
use Civi\Osdi\ActionNetwork\Object\Tagging as OsdiTaggingObject;
use Civi\Osdi\ActionNetwork\RemoteSystem;
use Civi\Osdi\ActionNetwork\Syncer\Person as PersonSyncer;
use Civi\Osdi\ActionNetwork\Syncer\Tag as TagSyncer;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\SyncResult;
use Civi\Osdi\ActionNetwork\Matcher\OneToOneEmailOrFirstLastEmail;

class Tagging {

  private array $syncProfile;

  private RemoteSystem $remoteSystem;

  /**
   * @var mixed
   */
  private $matcher;

  private Person $mapper;

  private PersonSyncer $personSyncer;

  private TagSyncer $tagSyncer;

  const inputTypeActionNetworkTaggingObject = 'ActionNetwork:Tagging:Object';
  const inputTypeLocalEntityTagId = 'Local:EntityTag:Id';

  public function __construct(RemoteSystem $remoteSystem) {
    $this->remoteSystem = $remoteSystem;
  }

  public function setRemoteSystem(RemoteSystem $system): void {
    $this->remoteSystem = $system;
  }

  public function getRemoteSystem(): RemoteSystem {
    return $this->remoteSystem;
  }

  public function getMapper() {
    if (empty($this->mapper)) {
      $mapperClass = $this->getSyncProfile()['mapper'];
      $this->mapper = new $mapperClass($this->getRemoteSystem());
    }
    return $this->mapper;
  }

  public function setMapper($mapper): void {
    $this->mapper = $mapper;
  }

  public function getMatcher(): OneToOneEmailOrFirstLastEmail {
    if (empty($this->matcher)) {
      $matcherClass = $this->getSyncProfile()['matcher'];
      $this->matcher = new $matcherClass($this->getRemoteSystem());
    }
    return $this->matcher;
  }

  public function setMatcher($matcher): void {
    $this->matcher = $matcher;
  }

  public function getSyncProfile(): array {
    return $this->syncProfile;
  }

  public function setSyncProfile(array $syncProfile): void {
    $this->syncProfile = $syncProfile;
  }

  private function getPersonSyncer(): PersonSyncer {
    if (empty($this->personSyncer)) {
      $this->personSyncer = new PersonSyncer($this->getRemoteSystem());
      $this->personSyncer->setSyncProfile($this->getSyncProfile());
    }
    return $this->personSyncer;
  }

  private function getTagSyncer(): TagSyncer {
    if (empty($this->tagSyncer)) {
      $this->tagSyncer = new tagSyncer($this->getRemoteSystem());
      $this->tagSyncer->setSyncProfile($this->getSyncProfile());
    }
    return $this->tagSyncer;
  }

  public function oneWaySync(string $inputType, $input): SyncResult {
    if (self::inputTypeActionNetworkTaggingObject === $inputType) {
      return $this->oneWaySyncRemoteObject($input);
    }
    if (self::inputTypeLocalEntityTagId === $inputType) {
      return $this->oneWaySyncLocalById($input);
    }
    throw new InvalidArgumentException(
      '%s is not a valid input type for ' . __CLASS__ . '::'
      . __FUNCTION__,
      $inputType
    );
  }

  private function oneWaySyncLocalById($id): SyncResult {
    try {
      $localObject = \Civi\Api4\EntityTag::get(FALSE)
        ->addWhere('id', '=', $id)
        ->addWhere('entity_table', '=', 'civicrm_contact')
        ->execute()->single();
    }
    catch (\API_Exception $exception) {
      return new SyncResult(
        NULL,
        NULL,
        SyncResult::ERROR,
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
    /** @var \Civi\Osdi\ActionNetwork\Object\TagOld $remoteTag */
    $remoteTag = $result->getRemoteObject();

    $system = $this->getRemoteSystem();

    $draftRemoteObject = new OsdiTaggingObject(NULL, NULL);
    $draftRemoteObject->setPerson($remotePerson, $system);
    $draftRemoteObject->setTag($remoteTag, $system);

    $saveResult = $system->trySave($draftRemoteObject);
    $remoteObject = $saveResult->getReturnedObject();

    $this->logSyncLocalEntityTag($saveResult, $id);

    if ($saveResult->isError()) {
      return new SyncResult(
        $localObject,
        $remoteObject,
        SyncResult::ERROR,
        $saveResult->getMessage(), NULL,
        $saveResult->getContext()
      );
    }

    $this->saveMatch($localObject, $remoteObject);

    return new SyncResult(
      $localObject,
      $remoteObject,
      SyncResult::SUCCESS, NULL, NULL,
    );
  }

  private function oneWaySyncRemoteObject(OsdiTaggingObject $remoteObject): SyncResult {

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

    return new SyncResult(
      $localObject,
      $remoteObject,
      SyncResult::SUCCESS, NULL, NULL,
    );
  }

  private function saveMatch(array $localObject, \Civi\Osdi\RemoteObjectInterface $remoteObject): void {
    $syncProfileId = self::getSyncProfile()['id'];
    $inputTypeLocal = self::inputTypeLocalEntityTagId;
    $inputTypeRemote = self::inputTypeActionNetworkTaggingObject;

    $savedMatches = self::getAllSavedMatches();

    $savedMatches[$syncProfileId][$inputTypeLocal][$localObject['id']] = [
      'local' => $localObject,
      'remote' => $remoteObject->getAllOriginal() + [
        'id' => $remoteObject->getId(),
      ],
    ];

    $savedMatches[$syncProfileId][$inputTypeRemote][$remoteObject->getId()] =
      &$savedMatches[$syncProfileId][$inputTypeLocal][$localObject['id']];

    \Civi::cache('short')->set('osdi-client:tagging-match', $savedMatches);
  }

  /**
   * @return array{local: array, remote: array}
   */
  public function getSavedMatch(string $inputType, $input, int $syncProfileId = NULL): array {
    $syncProfileId = $syncProfileId ?? $this->getSyncProfile()['id'];
    $savedMatches = self::getAllSavedMatches()[$syncProfileId] ?? [];

    if (self::inputTypeLocalEntityTagId === $inputType) {
      return $savedMatches[$inputType][$input] ?? [];
    }

    if (self::inputTypeActionNetworkTaggingObject === $inputType) {
      return $savedMatches[$inputType][$input->getId()] ?? [];
    }

    throw new \Exception("\"$inputType\" is not a valid way to look up saved Tagging matches");
  }

  private function getAllSavedMatches(): array {
    return \Civi::cache('short')->get('osdi-client:tagging-match', []);
  }

  /**
   * @param \Civi\Osdi\SaveResult $saveResult
   * @param $id
   *
   * @return void
   */
  private function logSyncLocalEntityTag(\Civi\Osdi\SaveResult $saveResult, $id): void {
    $logContext = [];
    if ($message = $saveResult->getMessage()) {
      $logContext[] = $message;
    }
    if ($saveResult->isError()) {
      $logContext[] = $saveResult->getContext();
    }
    \Civi::log()->debug(
      "OSDI sync attempt: EntityTag $id: {$saveResult->getStatus()}",
      $logContext,
    );
  }

}
