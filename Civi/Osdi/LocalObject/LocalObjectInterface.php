<?php

namespace Civi\Osdi\LocalObject;

interface LocalObjectInterface {

  public function getId(): ?int;

  public function setId(int $value);

  public function getAll(): array;

  public function getAllLoaded(): array;

  public function isTouched(): bool;

  public function isAltered(): bool;

  public function isLoaded(): bool;

  public function load(): LocalObjectInterface;

  public function loadOnce(): LocalObjectInterface;

  public function save(): LocalObjectInterface;

  public function delete(): ?\Civi\Api4\Generic\Result;

}