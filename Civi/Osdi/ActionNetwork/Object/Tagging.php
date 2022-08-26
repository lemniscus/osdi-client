<?php

namespace Civi\Osdi\ActionNetwork\Object;

use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\Exception\InvalidOperationException;
use Civi\Osdi\RemoteObjectInterface;

class Tagging extends Base implements RemoteObjectInterface {

  protected bool $_isAltered = FALSE;
  protected Person $person;
  protected Tag $tag;

  public Field $personHref;
  public Field $tagHref;
  public Field $identifiers;
  public Field $createdDate;
  public Field $modifiedDate;
  public Field $itemType;

  const FIELDS = [
    'personHref' => ['path' => ['_links', 'osdi:person', 'href'], 'createOnly' => TRUE],
    'tagHref' => ['path' => ['_links', 'osdi:tag', 'href'], 'readOnly' => TRUE],
    'identifiers' => ['path' => ['identifiers'], 'appendOnly' => TRUE],
    'createdDate' => ['path' => ['created_date'], 'readOnly' => TRUE],
    'modifiedDate' => ['path' => ['modified_date'], 'readOnly' => TRUE],
    'itemType' => ['path' => ['item_type'], 'readOnly' => TRUE],
  ];

  public function getType(): string {
    return 'osdi:taggings';
  }

  public function getUrlForCreate(): string {
    if (empty($tagUrl = $this->tagHref->get())) {
      if (!($tagUrl = $this->tag->getUrlForRead())) {
        throw new InvalidOperationException('Cannot construct a url to '
          . 'create a tagging; missing tag url');
      }
    }
    return "$tagUrl/taggings";
  }

  public function getUrlForRead(): ?string {
    return parent::getUrlForRead();
  }

  public function delete() {
    return $this->_system->delete($this);
  }

  public function setPerson(RemoteObjectInterface $person) {
    /** @var \Civi\Osdi\ActionNetwork\Object\Person $person */
    if (!($url = $person->getUrlForRead())) {
      throw new InvalidArgumentException("We need to know the person's URL");
    }
    if ($this->_id) {
      throw new InvalidOperationException('Modifying an existing tagging is not allowed');
    }
    $this->personHref->set($url);
    $this->person = $person;
  }

  public function setTag(RemoteObjectInterface $tag) {
    if ($this->_id) {
      throw new InvalidOperationException('Modifying an existing tagging is not allowed');
    }
    /** @var \Civi\Osdi\ActionNetwork\Object\Tag $tag */
    $this->tag = $tag;
    $this->touch();
    $this->_isAltered = TRUE;
  }

  public function getPerson(): ?Person {
    if (empty($this->person)) {
      $personResource = $this->_resource->getFirstLink('osdi:person')->get();
      $this->person = new Person($this->_system, $personResource);
    }
    return $this->person;
  }

  public function getTag(): Tag {
    if (empty($this->tag)) {
      $tagResource = $this->_resource->getFirstLink('osdi:tag')->get();
      $this->tag = new Tag($this->_system, $tagResource);
    }
    return $this->tag;
  }

  public function isAltered(): bool {
    return $this->_isAltered || parent::isAltered();
  }

}
