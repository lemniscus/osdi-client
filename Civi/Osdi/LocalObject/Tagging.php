<?php

namespace Civi\Osdi\LocalObject;

use Civi\Osdi\Exception\InvalidArgumentException;

class Tagging extends Base implements LocalObjectInterface {

  const FIELDS = [
    'id' => ['select' => 'id'],
    'contactId' => ['select' => 'entity_id'],
    'tagId' => ['select' => 'tag_id'],
  ];

  protected Field $contactId;

  protected Field $tagId;

  protected ?LocalObjectInterface $localPerson = NULL;

  protected ?LocalObjectInterface $localTag = NULL;

  public static function getCiviEntityName(): string {
    return 'EntityTag';
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

  public function loadFromArray(array $array) {
    parent::loadFromArray($array);
    $this->updateReferencedObjectsFromIdFields();
  }

  public function save(): self {
    if (is_null($p = $this->localPerson) || is_null($t = $this->localTag)) {
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

  public function setPerson(LocalObjectInterface $localPerson): self {
    if ('Contact' !== $localPerson->getCiviEntityName()) {
      throw new InvalidArgumentException();
    }
    $this->localPerson = $localPerson;
    $this->touch();
    return $this;
  }

  public function setTag(LocalObjectInterface $localTag): self {
    if ('Tag' !== $localTag->getCiviEntityName()) {
      throw new InvalidArgumentException();
    }
    $this->localTag = $localTag;
    $this->touch();
    return $this;
  }

  protected function getWhereClauseForLoad(): array {
    return [['id', '=', $this->getId()], ['entity_table', '=', 'civicrm_contact']];
  }

  protected function updateIdFieldsFromReferencedObjects() {
    $contactId = is_null($this->localPerson) ? NULL : $this->localPerson->getId();
    $tagId = is_null($this->localTag) ? NULL : $this->localTag->getId();
    $this->contactId->set($contactId);
    $this->tagId->set($tagId);
  }

  protected function updateReferencedObjectsFromIdFields() {
    $newContactId = $this->contactId->get();
    $newTagId = $this->tagId->get();

    if (is_null($this->localPerson) || ($newContactId !== $this->localPerson->getId())) {
      $this->setPerson(new Person($newContactId));
    }
    if (is_null($this->localTag) || ($newTagId !== $this->localTag->getId())) {
      $this->setTag(new Tag($newTagId));
    }
  }

}
