<?php

namespace Civi\Osdi\ActionNetwork\Syncer;

use Civi\Osdi\ActionNetwork\Mapper\Example;
use Civi\Osdi\ActionNetwork\Object\Tag as OsdiTagObject;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\SyncResult;
use Civi\Osdi\ActionNetwork\Matcher\OneToOneEmailOrFirstLastEmail;

class Tag {

  const inputTypeLocalTagId = 'Local:Tag:Id';
  const inputTypeActionNetworkTagObject = 'ActionNetwork:Tag:Object';

  private array $syncProfile;

  private RemoteSystemInterface $remoteSystem;

  /**
   * @var mixed
   */
  private $matcher;

  private Example $mapper;

  /**
   * Syncer constructor.
   */
  public function __construct(RemoteSystemInterface $remoteSystem) {
    $this->remoteSystem = $remoteSystem;
  }

  public function setRemoteSystem(RemoteSystemInterface $system): void {
    $this->remoteSystem = $system;
  }

  public function getRemoteSystem(): RemoteSystemInterface {
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

  public function oneWaySync(string $inputType, $input) {
    if (self::inputTypeActionNetworkTagObject === $inputType) {
      return $this->oneWaySyncRemoteObject($input);
    }
    if (self::inputTypeLocalTagId === $inputType) {
      return $this->oneWaySyncLocalById($input);
    }
    throw new InvalidArgumentException(
      '%s is not a valid input type for ' . __CLASS__ . '::'
      . __FUNCTION__,
      $inputType
    );
  }

  public function getOrCreateMatch(string $inputType, $input): SyncResult {
    if (self::inputTypeActionNetworkTagObject === $inputType) {
      return $this->getOrCreateMatchForRemoteObject($input);
    }
    if (self::inputTypeLocalTagId === $inputType) {
      return $this->getOrCreateMatchForLocalById($input);
    }
    throw new InvalidArgumentException(
      '%s is not a valid input type for ' . __CLASS__ . '::'
      . __FUNCTION__,
      $inputType
    );
  }

  private function getOrCreateMatchForRemoteObject(OsdiTagObject $tag) {
    $type = self::inputTypeActionNetworkTagObject;
    $savedMatch = $this->getSavedMatch($type, $tag);
    if ($savedMatch && ($localId = $savedMatch[$type] ?? NULL)) {
      $localTagArray = \Civi\Api4\Tag::get(FALSE)
        ->addWhere('id', '=', $localId)
        ->execute()->single();
      return new SyncResult($localTagArray, NULL,
        SyncResult::SUCCESS,
        'saved match',
        $savedMatch);
    }
    return $this->oneWaySyncRemoteObject($tag);
  }

  private function getOrCreateMatchForLocalById(int $id): SyncResult {
    $type = self::inputTypeLocalTagId;
    $savedMatch = $this->getSavedMatch($type, $id);
    if ($savedMatch && ($remoteObjectId = $savedMatch[$type] ?? NULL)) {
      $remoteObject = $this->getRemoteSystem()->fetchById('osdi:tags', $id);
      return new SyncResult([], $remoteObject,
        SyncResult::SUCCESS,
        'saved match',
        $savedMatch);
    }
    return $this->oneWaySyncLocalById($id);
  }

  /**
   * @return array{local: array, remote: array}
   */
  public function getSavedMatch(string $inputType, $input, int $syncProfileId = NULL) {
    $syncProfileId = $syncProfileId ?? $this->getSyncProfile()['id'];
    $savedMatches = self::getAllSavedMatches()[$syncProfileId] ?? [];

    if (self::inputTypeActionNetworkTagObject === $inputType) {
      $input = $input->getId();
    }
    elseif (self::inputTypeLocalTagId !== $inputType) {
      throw new \Exception("\"$inputType\" is not a valid way to look up saved Tag matches");
    }

    return $savedMatches[$inputType][$input] ?? [];
  }

  private function getAllSavedMatches(): array {
    return \Civi::cache('long')->get('osdi-client:tag-match', []);
  }

  private function oneWaySyncLocalById($id): SyncResult {
    try {
      $localTag = \Civi\Api4\Tag::get(FALSE)
        ->addWhere('id', '=', $id)
        ->execute()->single();

      $draftRemoteTag = new OsdiTagObject(NULL, ['name' => $localTag['name']]);
      $saveResult = $this->getRemoteSystem()->trySave($draftRemoteTag);
      $remoteObject = $saveResult->object();

      $logContext = [];
      if ($message = $saveResult->message()) {
        $logContext[] = $message;
      }
      if ($saveResult->isError()) {
        $logContext[] = $saveResult->context();
      }
      \Civi::log()->debug(
        "OSDI sync attempt: local tag $id: {$saveResult->status()}",
        $logContext,
      );

      if ($saveResult->isError()) {
        return new SyncResult(
          $localTag,
          $remoteObject,
          SyncResult::ERROR,
          $saveResult->message(),
          $saveResult->context()
        );
      }

      $this->saveMatch($localTag, $remoteObject);

      return new SyncResult(
        $localTag,
        $remoteObject,
        SyncResult::SUCCESS,
      );
    }

    catch (\API_Exception $exception) {
      return new SyncResult(
        NULL,
        NULL,
        SyncResult::ERROR,
        "Failed to retrieve local tag id '$id'",
        $exception
      );
    }
  }

  private function oneWaySyncRemoteObject(OsdiTagObject $remoteObject): SyncResult {
    $name = $remoteObject->get('name');

    if (empty($name)) {
      throw new InvalidArgumentException('Invalid remote tag object supplied to '
        . __CLASS__ . '::' . __FUNCTION__);
    }

    $civiApiTagGet = \Civi\Api4\Tag::get(FALSE)
      ->addWhere('name', '=', $name)
      ->execute();

    if (!$civiApiTagGet->count()) {
      $localTag = \Civi\Api4\Tag::create(FALSE)
        ->addValue('name', $name)
        ->addValue('used_for', ['Contact'])
        ->execute()->single();
    }
    else {
      $localTag = $civiApiTagGet->single();
    }

    \Civi::log()->debug(
      "OSDI sync attempt: remote tag '$name': success"
    );

    $this->saveMatch($localTag, $remoteObject);

    return new SyncResult(
      $localTag,
      $remoteObject,
      SyncResult::SUCCESS
    );
  }

  private function saveMatch(array $localTag, \Civi\Osdi\RemoteObjectInterface $remoteObject): void {
    $savedMatches = self::getAllSavedMatches();
    $recordToSave = [
      'local' => $localTag,
      'remote' => $remoteObject->getAllOriginal() + [
        'id' => $remoteObject->getId(),
      ],
    ];
    $syncProfileId = self::getSyncProfile()['id'];
    $savedMatches[$syncProfileId][self::inputTypeLocalTagId][$localTag['id']] = $recordToSave;
    $savedMatches[$syncProfileId][self::inputTypeActionNetworkTagObject][$remoteObject->getId()] =
      &$savedMatches[$syncProfileId][self::inputTypeLocalTagId][$localTag['id']];

    \Civi::cache('long')->set('osdi-client:tag-match', $savedMatches);
  }

}