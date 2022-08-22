<?php

namespace Civi\Osdi\Result;

use Civi\Osdi\LocalObject\LocalObjectInterface;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\RemoteObjectInterface;

class Map extends AbstractResult implements \Civi\Osdi\ResultInterface {

  use SimpleErrorTrait;

  const ERROR = 'error';

  const SKIPPED_ALL_CHANGES = 'skipped all changes';

  const SUCCESS = 'success';

}