<?php

namespace Civi\Osdi;

interface SyncStateInterface {

  public static function getDbTable();

  public function getId(): ?int;

  public function getLocalObject(): ?LocalObjectInterface;

  public function getRemoteObject(): ?RemoteObjectInterface;

}