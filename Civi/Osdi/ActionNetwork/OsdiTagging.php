<?php

namespace Civi\Osdi\ActionNetwork;

use Civi\Osdi\ActionNetwork\Object\Person;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\Exception\InvalidOperationException;
use Civi\Osdi\RemoteObjectInterface;
use Jsor\HalClient\HalResource;

class OsdiTagging extends OsdiObject {

  /**
   * @var Person
   */
  private $person;

  /**
   * @var OsdiObject
   */
  private $tag;

  /**
   * OsdiTagging constructor.
   *
   * @param \Jsor\HalClient\HalResource|null $resource
   * @param array|null $initData
   */
  public function __construct(?HalResource $resource, ?array $initData) {
    parent::__construct('osdi:taggings', $resource, $initData);
  }

  public function setPerson(Person $person, RemoteSystem $system) {
    if (!($url = $person->getOwnUrl($system))) {
      throw new InvalidArgumentException("We need to know the person's URL");
    }
    if ($this->id) {
      throw new InvalidOperationException('Modifying an existing tagging is not allowed');
    }
    $this->set('_links', ['osdi:person' => ['href' => $url]]);
    $this->person = $person;
  }

  /**
   * @param \Civi\Osdi\RemoteObjectInterface $tag
   * @param RemoteSystem $system
   *
   * @throws InvalidArgumentException
   * @throws InvalidOperationException
   */
  public function setTag(RemoteObjectInterface $tag, RemoteSystem $system) {
    if (!($url = $tag->getOwnUrl($system))) {
      throw new InvalidArgumentException("We need to know the tag's URL");
    }
    if ($this->id) {
      throw new InvalidOperationException('Modifying an existing tagging is not allowed');
    }
    $this->tag = $tag;
  }

  public function getPerson(): ?Person {
    if (!$this->person) {
      $personResource = $this->resource->getFirstLink('osdi:person')->get();
      $this->person = new Person($personResource);
    }
    return $this->person;
  }

  public function getTag(): ?OsdiObject {
    if (!$this->tag) {
      $tagResource = $this->resource->getFirstLink('osdi:tag')->get();
      $this->tag = new OsdiObject('osdi:tags', $tagResource);
    }
    return $this->tag;
  }

  public static function isValidField(string $name): bool {
    $validFields = [
      'identifiers',
      '_links',
    ];
    return in_array($name, $validFields);
  }

  public static function isMultipleValueField(string $name): bool {
    return ($name === 'identifiers');
  }

}
