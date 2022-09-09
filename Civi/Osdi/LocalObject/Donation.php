<?php

namespace Civi\Osdi\LocalObject;

class Donation extends Base implements LocalObjectInterface {

  public Field $createdDate;
  public Field $modifiedDate;
  public Field $name;

  const CIVI_ENTITY = 'Contribution';

  const FIELDS = [
    'id' => ['select' => 'id'],
    'createdDate' => ['select' => 'created_date', 'readOnly' => TRUE],
    'modifiedDate' => ['select' => 'modified_date', 'readOnly' => TRUE],
    'name' => ['select' => 'name'],
  ];

  public function save(): self {
    exit; // code not written.
    $returnedRecord = \Civi\Api4\Tag::save(FALSE)->addRecord([
      'id' => $this->getId(),
      'name' => $this->name->get(),
    ])->execute()->first();

    $this->id->load($returnedRecord['id']);
    $this->name->load($returnedRecord['name']);

    $this->isLoaded = TRUE;

    return $this;
  }

}
