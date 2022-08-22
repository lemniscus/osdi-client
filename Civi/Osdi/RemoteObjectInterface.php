<?php

namespace Civi\Osdi;

use Civi\Osdi\Result\Save as SaveResult;
use Jsor\HalClient\HalResource;

interface RemoteObjectInterface {

  public function __construct(RemoteSystemInterface $system,
                              ?HalResource $resource = NULL);

  public static function loadFromId(string $id, RemoteSystemInterface $system);

  public function getAll(): array;

  public function getAllOriginal(): array;

  public function getArrayForCreate(): array;

  public function getArrayForUpdate(): array;

  public function setId(string $val);

  public function getId(): ?string;

  public function getResource(): ?HalResource;

  public function getType(): string;

  public function getUrlForCreate(): string;

  public function getUrlForDelete(): string;

  public function getUrlForRead(): ?string;

  public function getUrlForUpdate(): string;

  public function isAltered(): bool;

  public function isLoaded(): bool;

  public function isTouched(): bool;

  public function isSupersetOf($otherObject, array $ignoring): bool;

  public function delete();

  public function load(): RemoteObjectInterface;

  public function loadFromArray(array $flatFields): RemoteObjectInterface;

  public function loadOnce(): RemoteObjectInterface;

  public function save(): RemoteObjectInterface;

  public function trySave(): SaveResult;

}
