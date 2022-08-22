<?php

namespace Civi\Osdi;

interface ResultInterface {

  public function getContext();

  public function getContextAsArray(): array;

  public function getMessage(): ?string;

  public function getStatusCode(): ?string;

  public function getType(): string;

  public function isError(): bool;

  public function isStatus(string $statusCode): bool;

  public function toArray(): array;

}