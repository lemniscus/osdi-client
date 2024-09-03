<?php

namespace Civi\Osdi;

interface LocalObjectInterface extends CrudObjectInterface {

  public static function fromId(string $id): self;

  public function getAll(): array;

  public function getAllAsLoaded(): array;

  public static function getCiviEntityName(): string;

  public function loadFromArray(array $array): self;

  public function loadFromObject(LocalObjectInterface $otherObject);

}
