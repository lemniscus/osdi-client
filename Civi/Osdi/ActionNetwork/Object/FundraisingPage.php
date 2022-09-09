<?php

namespace Civi\Osdi\ActionNetwork\Object;

use Civi\Osdi\ActionNetwork\Object\Field;

/**
 * @property-read Field $identifiers
 * @property-read Field $createdDate
 * @property-read Field $modifiedDate
 * @property-read Field $origin_system
 * @property-read Field $name
 * @property-read Field $title
 */
class FundraisingPage extends AbstractRemoteObject implements \Civi\Osdi\RemoteObjectInterface {

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
      'origin_system' => ['path' => ['origin_system'], 'createOnly' => TRUE],
      'title' => ['path' => ['title']],
    ];
  }

  public function getType(): string {
    return 'osdi:fundraising_pages';
  }

  public function getUrlForCreate(): string {
    return 'https://actionnetwork.org/api/v2/fundraising_pages';
  }

}

