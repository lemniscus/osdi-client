<?php

namespace Civi\Osdi\LocalObject;

interface LocalObjectInterface {

  public function delete(): ?\Civi\Api4\Generic\Result;

  public static function fromId(string $id): self;

  public function getAll(): array;

  public function getAllLoaded(): array;

  public static function getCiviEntityName(): string;

  public function getId(): ?int;

  public function isAltered(): bool;

  public function isLoaded(): bool;

  public function isTouched(): bool;

  public function load(): LocalObjectInterface;

  public function loadFromArray(array $array);

  public function loadOnce(): LocalObjectInterface;

  public function save(): LocalObjectInterface;

  public function setId(int $value);

  public function trySave(): \Civi\Osdi\Result\Save;

}
