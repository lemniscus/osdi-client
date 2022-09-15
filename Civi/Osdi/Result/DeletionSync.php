<?php

namespace Civi\Osdi\Result;

use Civi\Osdi\ResultInterface;

class DeletionSync extends AbstractResult implements ResultInterface {

  use SimpleErrorTrait;

  const DELETED = 'deleted';

  const ERROR = 'error';

  const NOTHING_TO_DELETE = 'nothing to delete';

}
