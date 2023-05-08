<?php

namespace Civi\Osdi;

use Jsor\HalClient\HalResource;

interface RemoteObjectInterface extends CrudObjectInterface {

  public function __construct(RemoteSystemInterface $system,
                              ?HalResource $resource = NULL);

  public static function loadFromId(string $id, ?RemoteSystemInterface $system = NULL);

  public function getAll(): array;

  public function getAllOriginal(): array;

  public function getArrayForCreate(): array;

  public function getArrayForUpdate(): array;

  public function getResource(): ?HalResource;

  public function getType(): string;

  public function getUrlForCreate(): string;

  public function getUrlForDelete(): string;

  public function getUrlForRead(): ?string;

  public function getUrlForUpdate(): string;

  public function isSupersetOf($otherObject, array $ignoring): bool;

  public function loadFromArray(array $flatFields): RemoteObjectInterface;

}
