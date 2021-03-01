<?php

namespace Civi\Osdi\Exception;

class AmbiguousResultException extends \CRM_Core_Exception {

  public function __construct($message = 'More than one possibility found', ...$sprintf_args) {
    if ($sprintf_args) $message = sprintf($message, ...$sprintf_args);
    parent::__construct($message);
  }

}