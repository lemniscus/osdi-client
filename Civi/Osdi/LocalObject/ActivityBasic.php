<?php

namespace Civi\Osdi\LocalObject;

use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\LocalObjectInterface;

class ActivityBasic extends AbstractLocalObject implements LocalObjectInterface {

  private static ?array $fieldMetadata = NULL;

  public Field $id;
  public Field $activityDateTime;
  public Field $activityTypeId;
  public Field $activityTypeName;
  public Field $createdDate;
  public Field $details;
  public Field $mediumId;
  public Field $modifiedDate;
  public Field $statusId;
  public Field $subject;
  public Field $sourceContactId;

  protected ?LocalObjectInterface $sourcePerson = NULL;

  /**
   * @var \Civi\Osdi\LocalObjectInterface[]
   */
  protected array $targetPeople = [];

  protected function getFieldMetadata(): array {
    if (is_null(static::$fieldMetadata)) {
      static::$fieldMetadata = array_merge(
        static::getActivityFieldMetadata(),
        static::getActivityContactFieldMetadata()
      );
    }
    return static::$fieldMetadata;
  }

  protected static function getActivityFieldMetadata(): array {
    return [
      'id' => ['select' => 'id'],
      'activityDateTime' => ['select' => 'activity_date_time'],
      'activityTypeId' => ['select' => 'activity_type_id'],
      'activityTypeName' => ['select' => 'activity_type_id:name'],
      'createdDate' => ['select' => 'created_date'],
      'details' => ['select' => 'details'],
      'mediumId' => ['select' => 'medium_id'],
      'modifiedDate' => ['select' => 'modified_date'],
      'statusId' => ['select' => 'status_id'],
      'subject' => ['select' => 'subject'],
    ];
  }

  protected static function getActivityContactFieldMetadata() {
    return [
      'sourceContactId' => ['select' => 'source_contact.id'],
    ];
  }

  public static function getCiviEntityName(): string {
    return 'Activity';
  }

  public function getSourcePerson(): ?LocalObjectInterface {
    return $this->sourcePerson;
  }

  public function getTargetPeople(): array {
    return $this->targetPeople;
  }


  public function isAltered(): bool {
    if (!$this->isTouched()) {
      return FALSE;
    }
    $this->updateIdFieldsFromReferencedObjects();
    return parent::isAltered();
  }

  public function load(): self {
    parent::load();
    $this->updateReferencedObjectsFromIdFields();
    return $this;
  }

  public function loadFromArray(array $array): self {
    parent::loadFromArray($array);
    $this->updateReferencedObjectsFromIdFields();
    return $this;
  }

  public function persist(): self {
    if (is_null($p = $this->person) || is_null($t = $this->tag)) {
      throw new InvalidArgumentException(
        'Unable to save %s: missing Person or Tag', __CLASS__);
    }
    if (empty($contactId = $p->getId()) || empty($tagId = $t->getId())) {
      throw new InvalidArgumentException(
        'Person and Tag must both be saved before saving %s', __CLASS__);
    }

    $returnedRecord = \Civi\Api4\EntityTag::save(FALSE)->addRecord([
      'id' => $this->getId(),
      'entity_table' => 'civicrm_contact',
      'entity_id' => $contactId,
      'tag_id' => $tagId,
    ])->execute()->first();

    $this->loadFromArray($returnedRecord);

    return $this;
  }

  /**
   * @param \Civi\Osdi\LocalObjectInterface[] $localPeople
   */
  public function setTargets(array $localPeople): self {
    $this->targetPeople = $localPeople;
    $this->touch();
    return $this;
  }


  public function setSourcePerson(LocalObjectInterface $localPerson): self {
    if ('Person' !== $localPerson->getCiviEntityName()) {
      throw new InvalidArgumentException();
    }
    $this->person = $localPerson;
    $this->touch();
    return $this;
  }

  protected function getWhereClauseForLoad(): array {
    return [['id', '=', $this->getId()], ['entity_table', '=', 'civicrm_contact']];
  }

  protected function updateIdFieldsFromReferencedObjects() {
    $contactId = is_null($this->person) ? NULL : $this->person->getId();
    $tagId = is_null($this->tag) ? NULL : $this->tag->getId();
    $this->contactId->set($contactId);
    $this->tagId->set($tagId);
  }

  protected function updateReferencedObjectsFromIdFields() {
    $newContactId = $this->contactId->get();
    $newTagId = $this->tagId->get();

    if (is_null($this->person) || ($newContactId !== $this->person->getId())) {
      $this->setPerson(new PersonBasic($newContactId));
    }
    if (is_null($this->tag) || ($newTagId !== $this->tag->getId())) {
      $this->setTag(new TagBasic($newTagId));
    }
  }

}
