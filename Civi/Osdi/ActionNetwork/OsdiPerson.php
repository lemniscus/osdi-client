<?php


namespace Civi\Osdi\ActionNetwork;

use Jsor\HalClient\HalResource;

class OsdiPerson extends OsdiObject {

  public function __construct(?HalResource $resource = null, ?array $initData = null) {
    parent::__construct('osdi:people', $resource, $initData);
  }

  public function getOriginalEmailAddress(): string
  {
      return $this->getOriginal('email_addresses')[0]['address'];
  }

  public static function isValidField(string $name): bool {
    $validFields = [
        'identifiers',
        'created_date',
        'modified_date',
        'family_name',
        'given_name',
        'languages_spoken',
        'postal_addresses',
        'email_addresses',
        'phone_numbers',
        'custom_fields',
    ];
    return in_array($name, $validFields);
  }

  public static function isMultipleValueField(string $name): bool {
    return ($name === 'identifiers');
  }

  public static function isClearableField(string $fieldName): bool {
    return FALSE;
  }

}