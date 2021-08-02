<?php

namespace Civi\Osdi\ActionNetwork;

use Jsor\HalClient\HalResource;

class OsdiPerson extends OsdiObject {

  public function __construct(?HalResource $resource = NULL, ?array $initData = NULL) {
    parent::__construct('osdi:people', $resource, $initData);
  }

  public function get(string $fieldName) {
    $val = parent::get($fieldName);

    $specialFields = [
      'given_name' => [[]],
      'family_name' => [[]],
      //'email_addresses' => [[0, 'address']],
      //'phone_numbers' => [[0, 'number']],
      // see note at \Civi\Osdi\ActionNetwork\OsdiPerson::blank
      'postal_addresses' => [[0, 'address_lines', 0], [0, 'postal_code']],
    ];

    if (!in_array($fieldName, array_keys($specialFields))) {
      return $val;
    }

    foreach ($specialFields[$fieldName] as $path) {
      if ("\xE2\x90\x80" === \CRM_Utils_Array::pathGet($val, $path)) {
        if (is_array($val)) {
          \CRM_Utils_Array::pathSet($val, $path, NULL);
        }
        else {
          $val = NULL;
        }
      }
    }

    return $val;
  }

  public function getEmailAddress(): ?string {
    return $this->get('email_addresses')[0]['address'];
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

  public static function blank(): OsdiPerson {
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

    return new OsdiPerson(NULL, $blank);
  }

}
