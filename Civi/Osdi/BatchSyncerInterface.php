<?php

namespace Civi\Osdi;

interface BatchSyncerInterface {

  public function __construct(SingleSyncerInterface $singleSyncer = NULL);

  public function batchSyncFromRemote(): ?int;

  public function batchSyncFromLocal(): ?int;

}
