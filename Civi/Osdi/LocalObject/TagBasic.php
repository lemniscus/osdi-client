<?php

namespace Civi\Osdi\LocalObject;

use Civi\Osdi\LocalObjectInterface;

class TagBasic extends AbstractLocalObject implements LocalObjectInterface {

  public Field $createdDate;
  public Field $modifiedDate;
  public Field $name;

  public static function getCiviEntityName(): string {
    return 'Tag';
  }

  protected static function getFieldMetadata() {
    return [
      'id' => ['select' => 'id'],
      'createdDate' => ['select' => 'created_date', 'readOnly' => TRUE],
      'modifiedDate' => ['select' => 'modified_date', 'readOnly' => TRUE],
      'name' => ['select' => 'name'],
    ];
  }

  public function persist(): self {
    $id = $this->getId();
    $name = $this->name->get();

    $recordToSave = ['id' => $id, 'name' => $name];
    if (empty($id)) {
      $recordToSave['label'] = $name;
    }

    $returnedRecord = \Civi\Api4\Tag::save(FALSE)
      ->setMatch(['name'])->addRecord($recordToSave)->execute()->first();

    $this->id->load($returnedRecord['id']);
    $this->name->load($returnedRecord['name']);

    $this->isLoaded = TRUE;

    return $this;
  }

}
