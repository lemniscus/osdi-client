<?php

namespace Civi\Osdi\ActionNetwork\Object;

use Civi\Osdi\ActionNetwork\RemoteFindResult;

class Tag extends AbstractRemoteObject implements \Civi\Osdi\RemoteObjectInterface {

  public Field $identifiers;
  public Field $createdDate;
  public Field $modifiedDate;
  public Field $name;

  protected function getFieldMetadata() {
    return [
      'identifiers' => ['path' => ['identifiers'], 'appendOnly' => TRUE],
      'createdDate' => ['path' => ['created_date'], 'readOnly' => TRUE],
      'modifiedDate' => ['path' => ['modified_date'], 'readOnly' => TRUE],
      'name' => ['path' => ['name'], 'createOnly' => TRUE],
    ];
  }

  public function getType(): string {
    return 'osdi:tags';
  }

  public function getUrlForCreate(): string {
    return 'https://actionnetwork.org/api/v2/tags';
  }

  public function getTaggings(): RemoteFindResult {
    $tagUrl = $this->getUrlForRead();
    $taggingsLink = $this->_system->linkify("$tagUrl/taggings");
    return new RemoteFindResult($this->_system, 'osdi:taggings', $taggingsLink);
  }

}
