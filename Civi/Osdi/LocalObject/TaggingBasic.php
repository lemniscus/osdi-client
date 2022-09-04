<?php

namespace Civi\Osdi\LocalObject;

use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\LocalObjectInterface;

class TaggingBasic extends AbstractLocalObject implements LocalObjectInterface {

  const FIELDS = [
    'id' => ['select' => 'id'],
    'contactId' => ['select' => 'entity_id'],
    'tagId' => ['select' => 'tag_id'],
  ];

  protected Field $contactId;

  protected Field $tagId;

  protected ?LocalObjectInterface $person = NULL;

  protected ?LocalObjectInterface $tag = NULL;

  public static function getCiviEntityName(): string {
    return 'EntityTag';
  }

  public function getPerson(): ?LocalObjectInterface {
    return $this->person;
  }

  public function getTag(): ?LocalObjectInterface {
    return $this->tag;
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

  public function setPerson(LocalObjectInterface $localPerson): self {
    if ('Contact' !== $localPerson->getCiviEntityName()) {
      throw new InvalidArgumentException();
    }
    $this->person = $localPerson;
    $this->touch();
    return $this;
  }

  public function setTag(LocalObjectInterface $localTag): self {
    if ('Tag' !== $localTag->getCiviEntityName()) {
      throw new InvalidArgumentException();
    }
    $this->tag = $localTag;
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
