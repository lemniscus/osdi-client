<?php

namespace Civi\Osdi\Result;

class Map extends AbstractResult implements \Civi\Osdi\ResultInterface {

  use SimpleErrorTrait;

  const ERROR = 'error';

  const SKIPPED_ALL_CHANGES = 'skipped all changes';

  const SUCCESS = 'success';

}
