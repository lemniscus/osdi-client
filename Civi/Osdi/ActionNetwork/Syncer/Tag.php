<?php

namespace Civi\Osdi\ActionNetwork\Syncer;

use Civi\Osdi\ActionNetwork\Mapper\Example;
use Civi\Osdi\ActionNetwork\Object\Tag as OsdiTagObject;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\SyncResult;
use Civi\Osdi\ActionNetwork\Matcher\OneToOneEmailOrFirstLastEmail;

class Tag {

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
    if ('ActionNetwork:Tag:Object' === $inputType) {
      return $this->syncRemoteTag($input);
    }
    if ('Local:Tag:Id' === $inputType) {
      return $this->syncLocalTagById($input);
    }
    throw new InvalidArgumentException(
      '%s is not a valid input type for ' . __CLASS__ . '::'
      . __FUNCTION__,
      $inputType
    );
  }

  public function getSavedMatch(int $syncProfileId, string $inputType, $input) {
    $savedMatches = self::getAllSavedMatches()[$syncProfileId] ?? [];

    if ('Local:Tag:Id' !== $inputType) {
      throw new \Exception("\"$inputType\" is not a valid way to look up saved Tag matches");
    }

    return $savedMatches[$input] ?? [];
  }

  private function getAllSavedMatches(): array {
    return \Civi::cache('long')->get('osdi-client:tag-match', []);
  }

  private function syncLocalTagById($id) {
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
        "OSDI sync attempt: tag $id: {$saveResult->status()}",
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

  private function saveMatch(array $localTag, \Civi\Osdi\RemoteObjectInterface $remoteObject): void {
    $savedMatches = self::getAllSavedMatches();
    $savedMatches[self::getSyncProfile()['id']][$localTag['id']] = [
      'local' => $localTag,
      'remote' => $remoteObject->getAllOriginal() + [
          'id' => $remoteObject->getId()
        ],
    ];
    \Civi::cache('long')->set('osdi-client:tag-match', $savedMatches);
  }

}