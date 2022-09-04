<?php

namespace Civi\Osdi\ActionNetwork\Object;

use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\Exception\InvalidOperationException;
use Civi\Osdi\RemoteObjectInterface;
use Jsor\HalClient\HalResource;

class Tagging extends AbstractRemoteObject implements RemoteObjectInterface {

  const FIELDS = [
    'personHref' => ['path' => ['_links', 'osdi:person'], 'createOnly' => TRUE],
    'tagHref' => ['path' => ['_links', 'osdi:tag'], 'createOnly' => TRUE],
    'identifiers' => ['path' => ['identifiers'], 'appendOnly' => TRUE],
    'createdDate' => ['path' => ['created_date'], 'readOnly' => TRUE],
    'modifiedDate' => ['path' => ['modified_date'], 'readOnly' => TRUE],
    'itemType' => ['path' => ['item_type'], 'readOnly' => TRUE],
  ];

  protected Field $personHref;

  protected Field $tagHref;

  public Field $identifiers;

  public Field $createdDate;

  public Field $modifiedDate;

  public Field $itemType;

  protected ?RemoteObjectInterface $person = NULL;

  protected ?RemoteObjectInterface $tag = NULL;

  public function delete() {
    return $this->_system->delete($this);
  }

  public function getArrayForCreate(): array {
    return [
      '_links' => [
        'osdi:person' => ['href' => $this->personHref->get()],
      ],
    ];
  }

  public function getPerson(): ?Person {
    if (empty($this->person)) {
      $personResource = $this->_resource->getFirstLink('osdi:person')->get();
      $this->person = new Person($this->_system, $personResource);
    }
    return $this->person;
  }

  public function setPerson(RemoteObjectInterface $person) {
    if ('osdi:people' !== $person->getType()) {
      throw new InvalidArgumentException();
    }
    $newPersonUrl = $this->setPersonWithoutSettingField($person);
    $this->personHref->set($newPersonUrl);
  }

  public function setPersonWithoutSettingField(RemoteObjectInterface $person): ?string {
    $currentPersonUrl = $this->personHref->get();
    $newPersonUrl = $this->getUrlIfAnyFor($person);

    if ($this->_id && !empty($currentPersonUrl) && ($newPersonUrl !== $currentPersonUrl)) {
      throw new InvalidOperationException('Modifying an existing tagging on Action Network is not allowed');
    }

    $this->person = $person;
    return $newPersonUrl;
  }

  public function getTag(): Tag {
    if (empty($this->tag)) {
      $tagResource = $this->_resource->getFirstLink('osdi:tag')->get();
      $this->tag = new Tag($this->_system, $tagResource);
    }
    return $this->tag;
  }

  public function setTag(RemoteObjectInterface $tag) {
    if ('osdi:tags' !== $tag->getType()) {
      throw new InvalidArgumentException();
    }
    $newTagUrl = $this->setTagWithoutSettingField($tag);
    $this->tagHref->set($newTagUrl);
  }

  protected function setTagWithoutSettingField(RemoteObjectInterface $tag): ?string {
    $currentTagUrl = $this->tagHref->get();
    $newTagUrl = $this->getUrlIfAnyFor($tag);

    if ($this->_id && !empty($currentTagUrl) && ($newTagUrl !== $currentTagUrl)) {
      throw new InvalidOperationException('Modifying an existing tagging on Action Network is not allowed');
    }
    $this->tag = $tag;
    return $newTagUrl;
  }

  public function getType(): string {
    return 'osdi:taggings';
  }

  public function getUrlForCreate(): string {
    if (empty($tagUrl = $this->tagHref->get())) {
      if (!($tagUrl = $this->getUrlIfAnyFor($this->tag))) {
        throw new InvalidOperationException('Cannot construct a url to '
          . 'create a tagging; missing tag url');
      }
    }
    return "$tagUrl/taggings";
  }

  public function load(HalResource $resource = NULL): self {
    parent::load($resource);
    $this->updateReferencedObjectsFromFields();
    return $this;
  }

  public function save(): self {
    $this->updateFieldsFromReferencedObjects();

    if (empty($this->personHref->get()) || empty($this->tagHref->get())) {
      throw new InvalidArgumentException(
        'Unable to save %s: Person and Tag must both exist and have URLs', __CLASS__);
    }

    return parent::save();
  }

  protected function updateFieldsFromReferencedObjects() {
    if ($this->person) {
      $this->personHref->set($this->getUrlIfAnyFor($this->person));
    }
    if ($this->tag) {
      $this->tagHref->set($this->getUrlIfAnyFor($this->tag));
    }
  }

  protected function updateReferencedObjectsFromFields() {
    $newPersonUrl = $this->personHref->get();
    $newPersonId = substr($newPersonUrl, strrpos($newPersonUrl, '/') + 1);
    $newTagUrl = $this->tagHref->get();
    $newTagId = substr($newTagUrl, strrpos($newTagUrl, '/') + 1);

    if (is_null($this->person) || ($newPersonId !== $this->person->getId())) {
      $newPerson = new Person($this->_system);
      $newPerson->setId($newPersonId);
      $this->setPersonWithoutSettingField($newPerson);
    }
    if (is_null($this->tag) || ($newTagId !== $this->tag->getId())) {
      $newTag = new Tag($this->_system);
      $newTag->setId($newTagId);
      $this->setTagWithoutSettingField($newTag);
    }
  }

  private function getUrlIfAnyFor(?RemoteObjectInterface $object): ?string {
    if (is_null($object)) {
      return NULL;
    }
    try {
      return $object->getUrlForRead();
    }
    catch (EmptyResultException $e) {
      return NULL;
    }
  }

}
