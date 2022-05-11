<?php

namespace Civi\Osdi\LocalObject;

class Tag extends Base implements LocalObjectInterface {

  public Field $createdDate;
  public Field $modifiedDate;
  public Field $name;

  const CIVI_ENTITY = 'Tag';

  const FIELDS = [
    'id' => ['select' => 'id'],
    'createdDate' => ['select' => 'created_date', 'readOnly' => TRUE],
    'modifiedDate' => ['select' => 'modified_date', 'readOnly' => TRUE],
    'name' => ['select' => 'name'],
  ];

  public function save(): self {
    $id = \Civi\Api4\Tag::save(FALSE)->addRecord([
      'id' => $this->getId(),
      'name' => $this->name->get(),
    ])->execute()->first()['id'];

    $this->id->load($id);

    return $this;
  }

}
