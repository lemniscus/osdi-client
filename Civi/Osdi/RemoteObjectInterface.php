<?php

namespace Civi\Osdi;

interface RemoteObjectInterface {

  public function getNamespace(): string;

  public function getType(): string;

  public function getOwnUrl(RemoteSystemInterface $system);

  public function getId(): ?string;

  public function setId(string $id);

  /**
   * @param string|null $fieldName
   *
   * @return mixed
   * @throws \Civi\Osdi\Exception\InvalidArgumentException
   */
  public function get(string $fieldName);

  /**
   * @param string|null $fieldName
   * @return mixed
   * @throws \Civi\Osdi\Exception\InvalidArgumentException
   */
  public function getOriginal(string $fieldName);

  /**
   * @param string $fieldName
   * @return mixed|null
   */
  public function getAltered(string $fieldName);

  public function getAllAltered(): array;

  /**
   * @param string $fieldName
   * @param mixed $val
   * @throws \Civi\Osdi\Exception\InvalidArgumentException
   */
  public function set(string $fieldName, $val);

  /**
   * @param string $fieldName
   * @param mixed $val
   * @throws \Civi\Osdi\Exception\InvalidArgumentException
   */
  public function appendTo(string $fieldName, $val);

  /**
   * @param string $fieldName
   * @throws \Civi\Osdi\Exception\InvalidArgumentException
   */
  public function clearField(string $fieldName);

  public function getFieldsToClearBeforeWriting(): array;

  public function isEdited(string $fieldName): bool;

}
