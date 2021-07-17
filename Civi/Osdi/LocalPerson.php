<?php

namespace Civi\Osdi;

class LocalPerson {

  /**
   * CRM_OSDI_LocalPerson constructor.
   */
  public function __construct() {
  }

  public function getFields() {
    return [
      'email' => '',
      'first_name' => '',
      'last_name' => '',
      'local_id' => '',
    ];
  }

}
