<?php

namespace Civi\Osdi;

interface BatchSyncerInterface {

  public function __construct(SingleSyncerInterface $singleSyncer = NULL);

  public function batchSyncFromRemote(): ?string;

  public function batchSyncFromLocal(): ?string;

}
