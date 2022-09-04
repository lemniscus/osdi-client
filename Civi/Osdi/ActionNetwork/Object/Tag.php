<?php

namespace Civi\Osdi\ActionNetwork\Object;

class Tag extends AbstractRemoteObject implements \Civi\Osdi\RemoteObjectInterface {

  public Field $identifiers;
  public Field $createdDate;
  public Field $modifiedDate;
  public Field $name;

  const FIELDS = [
    'identifiers' => ['path' => ['identifiers'], 'appendOnly' => TRUE],
    'createdDate' => ['path' => ['created_date'], 'readOnly' => TRUE],
    'modifiedDate' => ['path' => ['modified_date'], 'readOnly' => TRUE],
    'name' => ['path' => ['name'], 'createOnly' => TRUE],
  ];

  public function getType(): string {
    return 'osdi:tags';
  }

  public function getUrlForCreate(): string {
    return 'https://actionnetwork.org/api/v2/tags';
  }

}
