<?php

namespace Civi\Osdi\ActionNetwork\Object;

use Civi\Osdi\ActionNetwork\OsdiObject;
use Jsor\HalClient\HalResource;

class Tag extends OsdiObject {

  public function __construct(?HalResource $resource = NULL, ?array $initData = NULL) {
    parent::__construct('osdi:tags', $resource, $initData);
  }

  public static function getValidFields(): array {
    return [
      'identifiers',
      'created_date',
      'modified_date',
      'name',
    ];
  }

  public static function isValidField(string $name): bool {
    return in_array($name, self::getValidFields());
  }

}
