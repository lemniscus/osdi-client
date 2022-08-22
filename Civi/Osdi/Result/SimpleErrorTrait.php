<?php

namespace Civi\Osdi\Result;

trait SimpleErrorTrait {

  public function isError(): bool {
    return $this->statusCode === self::ERROR;
  }

}
