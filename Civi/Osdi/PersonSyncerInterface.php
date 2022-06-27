<?php

namespace Civi\Osdi;

use Civi\Osdi\ActionNetwork\Object\Person as RemotePerson;

interface PersonSyncerInterface {

  public function syncFromRemoteIfNeeded(RemotePerson $remotePerson): SyncResult;

  public function getOrCreateLocalRemotePairFromRemote(RemotePerson $remotePerson): LocalRemotePair;

  public function getRemoteSystem(): RemoteSystemInterface;

}
