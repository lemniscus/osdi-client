<?php

namespace Civi\Osdi\Result;

class Generic extends AbstractResult implements \Civi\Osdi\ResultInterface {

  use SimpleErrorTrait;

  const ERROR = 'error';

  public function __construct(string $type = NULL) {
    $this->type = $type ?? static::class;
  }

}
