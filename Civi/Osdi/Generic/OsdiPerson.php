<?php

namespace Civi\Osdi\Generic;

use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Jsor\HalClient\HalResource;

class OsdiPerson extends OsdiObject implements RemoteObjectInterface {

  /**
   * @var string
   */
  protected $id;

  /**
   * @var string
   */
  protected $type = 'osdi:people';

  /**
   * Civi\Osdi\OsdiPerson constructor.
   *
   * @param \Jsor\HalClient\HalResource|null $resource
   * @param array|null $initData
   */
  public function __construct(HalResource $resource = NULL, array $initData = NULL) {
    $this->resource = $resource;
    if ($id = $this->extractIdFromResource($resource)) {
      $this->id = $id;
    }
    foreach ((array) $initData as $fieldName => $value) {
      if (static::isValidField($fieldName)) {
        $this->alteredData[$fieldName] = $value;
      }
    }
  }

  public static function isValidField(string $name): bool {
    $validFields = [
      'identifiers',
      'created_date',
      'modified_date',
      'family_name',
      'given_name',
      'additional_name',
      'honorific_prefix',
      'honorific_suffix',
      'gender',
      'gender_identity',
      'additional_gender_identities',
      'gender_pronouns',
      'party_identification',
      'parties',
      'source',
      'ethnicities',
      'languages_spoken',
      'preferred_language',
      'browser_url',
      'administrative_url',
      'birthdate',
      'employer',
      'work_title',
      'work_department',
      'employer_address',
      'postal_addresses',
      'email_addresses',
      'phone_numbers',
      'profiles',
      'custom_fields',
      'division_info',
    ];
    return in_array($name, $validFields);
  }

  public static function isMultipleValueField(string $name): bool {
    $multipleValueFields = [
      'identifiers',
      'additional_gender_identities',
      'gender_pronouns',
      'parties',
      'ethnicities',
      'languages_spoken',
      'postal_addresses',
      'email_addresses',
      'phone_numbers',
      'profiles',
      'custom_fields',
      'division_info',
    ];
    return in_array($name, $multipleValueFields);
  }

  public function getOwnUrl(RemoteSystemInterface $system): string {
    return $system->getPeopleUrl() . '/' . $this->getId();
  }

}
