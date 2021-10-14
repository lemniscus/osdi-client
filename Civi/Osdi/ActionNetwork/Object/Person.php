<?php

namespace Civi\Osdi\ActionNetwork\Object;

use Civi\Osdi\ActionNetwork\OsdiObject;
use Jsor\HalClient\HalResource;

class Person extends OsdiObject {

  protected static $fieldsWithNullWorkarounds = [
    'given_name' => [[]],
    'family_name' => [[]],
    //'email_addresses' => [[0, 'address']],
    //'phone_numbers' => [[0, 'number']],
    // see note at \Civi\Osdi\ActionNetwork\Object\Person::blank
    'postal_addresses' => [[0, 'address_lines', 0], [0, 'postal_code']],
  ];

  public function __construct(?HalResource $resource = NULL, ?array $initData = NULL) {
    parent::__construct('osdi:people', $resource, $initData);
  }

  protected static function restoreNulls($fieldName, $value) {
    $replacementPaths = static::$fieldsWithNullWorkarounds[$fieldName] ?? [];
    foreach ($replacementPaths as $path) {
      if ("\xE2\x90\x80" === \CRM_Utils_Array::pathGet($value, $path)) {
        if (is_array($value)) {
          \CRM_Utils_Array::pathSet($value, $path, NULL);
        }
        else {
          $value = NULL;
        }
      }
    }
    return $value;
  }

  public function get(string $fieldName) {
    $val = parent::get($fieldName);
    return self::restoreNulls($fieldName, $val);
  }

  public function toArray(): array {
    $arr = parent::toArray();
    foreach ($arr as $fieldName => $value) {
      $arr[$fieldName] = self::restoreNulls($fieldName, $value);
    }
    return $arr;
  }

  public function getEmailAddress(): ?string {
    return $this->get('email_addresses')[0]['address'];
  }

  public static function getValidFields(): array {
    return [
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
  }

  public static function isValidField(string $name): bool {
    return in_array($name, self::getValidFields());
  }

  public static function isMultipleValueField(string $name): bool {
    return ($name === 'identifiers');
  }

  public static function isClearableField(string $fieldName): bool {
    return FALSE;
  }

  public static function blank(): Person {
    $nullChar = "\xE2\x90\x80";

    /*
     * we leave the actual email address and phone number untouched, because
     * we aren't allowed to truly delete them, and we overwriting them with the
     * null char likely won't have the effect we want due to AN's deduplication
     * rules, https://help.actionnetwork.org/hc/en-us/articles/360038822392-Deduplicating-activists-on-Action-Network
     */

    $blank['email_addresses'][0]['status'] = 'unsubscribed';
    $blank['phone_numbers'][0]['status'] = 'unsubscribed';
    $blank['given_name'] = $nullChar;
    $blank['family_name'] = $nullChar;
    $blank['languages_spoken'] = ['en'];
    $blank['postal_addresses'][0]['address_lines'][0] = $nullChar;
    $blank['postal_addresses'][0]['postal_code'] = $nullChar;
    $blank['postal_addresses'][0]['country'] = '';

    return new Person(NULL, $blank);
  }

}
