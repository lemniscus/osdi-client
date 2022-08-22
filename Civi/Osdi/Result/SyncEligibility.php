<?php

namespace Civi\Osdi\Result;

class SyncEligibility extends AbstractResult implements \Civi\Osdi\ResultInterface {

  use SimpleErrorTrait;

  const ERROR = 'error';

  const ELIGIBLE = 'eligible';

  const INELIGIBLE = 'ineligible';

}
