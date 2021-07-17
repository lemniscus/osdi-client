<?php

namespace Civi\Osdi\Exception;

class InvalidOperationException extends \CRM_Core_Exception {

  public function __construct($message = 'Operation not allowed', ...$sprintf_args) {
    if ($sprintf_args) {
      $message = sprintf($message, ...$sprintf_args);
    }
    parent::__construct($message);
  }

}
