<?php

namespace Civi\Osdi\Exception;

class EmptyResultException extends \CRM_Core_Exception {

  public function __construct($message = 'Nothing found', ...$sprintf_args) {
    if ($sprintf_args) $message = sprintf($message, ...$sprintf_args);
    parent::__construct($message);
  }
}