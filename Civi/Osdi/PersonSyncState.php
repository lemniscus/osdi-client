<?php

namespace Civi\Osdi;

use Civi\Api4\Generic\DAOGetAction;
use Civi\Api4\OsdiPersonSyncState;
use Civi\Osdi\Exception\InvalidArgumentException;

class PersonSyncState {

  protected $data = [
    'id' => NULL,
    'contact_id' => NULL,
    'sync_profile_id' => NULL,
    'remote_person_id' => NULL,
    'remote_pre_sync_modified_time' => NULL,
    'remote_post_sync_modified_time' => NULL,
    'local_pre_sync_modified_time' => NULL,
    'local_post_sync_modified_time' => NULL,
    'sync_time' => NULL,
    'sync_origin' => NULL,
    'sync_status' => NULL,
  ];

  const ORIGIN_LOCAL = 0;
  const ORIGIN_REMOTE = 1;

  public function isError(): bool {
    $status = $this->getSyncStatus();

    if (empty($status)) {
      return FALSE;
    }

    $explodedStatus = explode('::', $status);

    if (2 !== count($explodedStatus)) {
      throw new \CRM_Core_Exception('Sync state code in invalid format');
    }

    [$resultClass, $statusConstant] = $explodedStatus;
    /** @var \Civi\Osdi\ResultInterface $resultObject */
    $resultObject = new $resultClass();
    $resultObject->setStatusCode($statusConstant);
    return $resultObject->isError();
  }

  public static function syncOriginPseudoConstant(): array {
    return [
      self::ORIGIN_LOCAL => ts('local'),
      self::ORIGIN_REMOTE => ts('remote'),
    ];
  }

  public static function getForRemotePerson(
    RemoteObjectInterface $remotePerson,
    ?int $syncProfileId
  ): self {
    return self::getForLocalOrRemotePerson('remote_person_id', $remotePerson, $syncProfileId);
  }

  public static function getForLocalPerson(
    LocalObjectInterface $localPerson,
    ?int $syncProfileId
  ): self {
    return self::getForLocalOrRemotePerson('contact_id', $localPerson, $syncProfileId);
  }

  private static function getForLocalOrRemotePerson(
    string $idField,
    $person,
    ?int $syncProfileId
  ): self {
    $osdiMatchGetAction = OsdiPersonSyncState::get(FALSE)
      ->addWhere($idField, '=', $person->getId());

    if ($syncProfileId) {
      $osdiMatchGetAction->addWhere('sync_profile_id', '=', $syncProfileId);
    }
    else {
      $osdiMatchGetAction->addWhere('sync_profile_id', 'IS NULL');
    }

    try {
      return self::createFromApiGetAction($osdiMatchGetAction);
    }
    catch (\CRM_Core_Exception $e) {
      if (preg_match(
        '/^Expected to find one .+ record, but there were multiple\.$/',
        $e->getMessage()
      )) {
        throw $e;
      }
      return new static();
    }
  }

  protected static function createFromApiGetAction(DAOGetAction $osdiMatchGetAction): self {
    $result = $osdiMatchGetAction->setSelect(['*'])->execute()->single();

    $return = new static();
    $return->setId($result['id']);
    $return->setContactId($result['contact_id']);
    $return->setSyncProfileId($result['sync_profile_id']);
    $return->setRemotePersonId($result['remote_person_id']);
    $return->setRemotePreSyncModifiedTime($result['remote_pre_sync_modified_time']);
    $return->setRemotePostSyncModifiedTime($result['remote_post_sync_modified_time']);
    $return->setLocalPreSyncModifiedTime($result['local_pre_sync_modified_time']);
    $return->setLocalPostSyncModifiedTime($result['local_post_sync_modified_time']);
    $return->setSyncTime($result['sync_time']);
    $return->setSyncOrigin($result['sync_origin']);
    $return->setSyncStatus($result['sync_status']);

    return $return;
  }

  public function save() {
    $id = OsdiPersonSyncState::save(FALSE)
      ->setMatch(['contact_id', 'remote_person_id', 'sync_profile_id'])
      ->setRecords([
        [
          'id' => $this->getId(),
          'contact_id' => $this->getContactId(),
          'sync_profile_id' => $this->getSyncProfileId(),
          'remote_person_id' => $this->getRemotePersonId(),
          'remote_pre_sync_modified_time' => $this->getRemotePreSyncModifiedTime(),
          'remote_post_sync_modified_time' => $this->getRemotePostSyncModifiedTime(),
          'local_pre_sync_modified_time' => $this->getLocalPreSyncModifiedTime(),
          'local_post_sync_modified_time' => $this->getLocalPostSyncModifiedTime(),
          'sync_time' => $this->getSyncTime(),
          'sync_origin' => $this->getSyncOrigin(),
          'sync_status' => $this->getSyncStatus(),
        ],
      ])->execute()->single()['id'];

    $this->setId($id);
  }

  public function delete() {
    if (empty($this->getId())) {
      throw new InvalidArgumentException("Can't delete a %s without an id", static::class);
    }
  }

  public function toArray(): array {
    return $this->data;
  }

  public function getId(): ?int {
    return $this->data['id'];
  }

  public function getContactId(): ?int {
    return $this->data['contact_id'];
  }

  public function getSyncProfileId(): ?int {
    return $this->data['sync_profile_id'];
  }

  public function getRemotePersonId(): ?string {
    return $this->data['remote_person_id'];
  }

  public function getRemotePreSyncModifiedTime(): ?string {
    return $this->data['remote_pre_sync_modified_time'];
  }

  public function getRemotePostSyncModifiedTime(): ?string {
    return $this->data['remote_post_sync_modified_time'];
  }

  public function getLocalPreSyncModifiedTime(): ?string {
    return $this->data['local_pre_sync_modified_time'];
  }

  public function getLocalPostSyncModifiedTime(): ?string {
    return $this->data['local_post_sync_modified_time'];
  }

  public function getSyncTime(): ?string {
    return $this->data['sync_time'];
  }

  public function getSyncOrigin(): ?bool {
    return $this->data['sync_origin'];
  }

  public function getSyncStatus(): ?string {
    return $this->data['sync_status'];
  }

  public function setId(?int $id): void {
    $this->data['id'] = $id;
  }

  public function setContactId(?int $contactId): void {
    $this->data['contact_id'] = $contactId;
  }

  public function setSyncProfileId(?int $syncProfileId): void {
    $this->data['sync_profile_id'] = $syncProfileId;
  }

  public function setRemotePersonId(?string $remotePersonId): void {
    $this->data['remote_person_id'] = $remotePersonId;
  }

  public function setRemotePreSyncModifiedTime(?string $remotePreSyncModifiedTime): void {
    $this->data['remote_pre_sync_modified_time'] = $remotePreSyncModifiedTime;
  }

  public function setRemotePostSyncModifiedTime(?string $remotePostSyncModifiedTime): void {
    $this->data['remote_post_sync_modified_time'] = $remotePostSyncModifiedTime;
  }

  public function setLocalPreSyncModifiedTime(?string $localPreSyncModifiedTime): void {
    $this->data['local_pre_sync_modified_time'] = $localPreSyncModifiedTime;
  }

  public function setLocalPostSyncModifiedTime(?string $localPostSyncModifiedTime): void {
    $this->data['local_post_sync_modified_time'] = $localPostSyncModifiedTime;
  }

  public function setSyncTime(?string $syncTime) {
    $this->data['sync_time'] = $syncTime;
  }

  public function setSyncOrigin(?bool $syncOrigin): void {
    $this->data['sync_origin'] = $syncOrigin;
  }

  public function setSyncStatus(?string $syncStatus): void {
    $this->data['sync_status'] = $syncStatus;
  }

}
