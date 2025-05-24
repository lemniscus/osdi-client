<?php

namespace Civi\Osdi\LocalObject;

use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\Phone;
use Civi\Osdi\LocalObjectInterface;

class PersonBasic extends AbstractLocalObject implements LocalObjectInterface {

  protected static ?array $fieldMetadata = NULL;

  public Field $createdDate;
  public Field $modifiedDate;
  public Field $firstName;
  public Field $lastName;
  public Field $isOptOut;
  public Field $doNotEmail;
  public Field $doNotSms;
  public Field $isDeleted;
  public Field $preferredLanguage;
  public Field $preferredLanguageName;
  public Field $emailId;
  public Field $emailEmail;
  public Field $phoneId;
  public Field $phonePhone;
  public Field $phonePhoneNumeric;
  public Field $addressId;
  public Field $addressStreetAddress;
  public Field $addressCity;
  public Field $addressStateProvinceId;
  public Field $addressStateProvinceIdAbbreviation;
  public Field $addressPostalCode;
  public Field $addressCountryId;
  public Field $addressCountryIdName;

  public static function getCiviEntityName(): string {
    return 'Contact';
  }

  public static function getFieldMetadata(): array {
    if (is_null(static::$fieldMetadata)) {
      static::$fieldMetadata = array_merge(
        static::getContactFieldMetadata(),
        static::getEmailFieldMetadata(),
        static::getPhoneFieldMetadata(),
        static::getAddressFieldMetadata()
      );
    }
    return static::$fieldMetadata;
  }

  protected static function getContactFieldMetadata(): array {
    return [
      'id' => ['select' => 'id'],
      'createdDate' => ['select' => 'created_date', 'readOnly' => TRUE],
      'modifiedDate' => ['select' => 'modified_date', 'readOnly' => TRUE],
      'firstName' => ['select' => 'first_name'],
      'lastName' => ['select' => 'last_name'],
      'isOptOut' => ['select' => 'is_opt_out'],
      'doNotEmail' => ['select' => 'do_not_email'],
      'doNotSms' => ['select' => 'do_not_sms'],
      'isDeleted' => ['select' => 'is_deleted'],
      'preferredLanguage' => ['select' => 'preferred_language'],
      'preferredLanguageName' => ['select' => 'preferred_language:name'],
    ];
  }

  protected static function getEmailFieldMetadata(): array {
    return [
      'emailId' => ['select' => 'email.id'],
      'emailEmail' => ['select' => 'email.email'],
    ];
  }

  protected static function getPhoneFieldMetadata(): array {
    return [
      'phoneId' => ['select' => 'phone.id'],
      'phonePhone' => ['select' => 'phone.phone'],
      'phonePhoneNumeric' => [
        'select' => 'phone.phone_numeric',
        'readOnly' => TRUE,
      ],
    ];
  }

  protected static function getAddressFieldMetadata(): array {
    return [
      'addressId' => ['select' => 'address.id'],
      'addressStreetAddress' => ['select' => 'address.street_address'],
      'addressCity' => ['select' => 'address.city'],
      'addressStateProvinceId' => [
        'select' => 'address.state_province_id',
        'afterSet' => 'updateStateAbbreviation',
      ],
      'addressStateProvinceIdAbbreviation' => [],
      'addressPostalCode' => ['select' => 'address.postal_code'],
      'addressCountryId' => ['select' => 'address.country_id'],
      'addressCountryIdName' => ['select' => 'address.country_id:name'],
    ];
  }

  public static function getJoins(): array {
    return [
      ['Email AS email', 'LEFT', NULL, ['email.is_primary', '=', 1]],
      ['Phone AS phone', 'LEFT', NULL,
        ['phone.phone_type_id:name', '=', '"Mobile"'],
      ],
      ['Address AS address', 'LEFT', NULL, ['address.is_primary', '=', 1]],
    ];
  }

  public static function getOrderBys(): array {
    return [
      'phone.id' => 'ASC',
    ];
  }

  protected function getWhereClauseForLoad(): array {
    return [['id', '=', $this->getId()], ['contact_type', '=', 'Individual']];
  }

  public function load(): self {
    parent::load();

    if ($this->isLoaded()) {
      $abrv = $this->getAbbreviatedState(
        $this->addressStateProvinceId,
        $this->addressCountryId
      );
      $this->addressStateProvinceIdAbbreviation->load($abrv ?? NULL);
    }

    return $this;
  }

  public function persist(): self {
    $cid = $this->saveCoreContactFields();
    $this->id->load($cid);
    $this->saveEmail($cid);
    $this->savePhone($cid);
    $this->saveAddress($cid);

    $this->load();

    return $this;
  }

  protected function saveCoreContactFields() {
    $record = $this->getContactRecordForSave();

    $postSaveContactArray = Contact::save(FALSE)
      ->addRecord($record)->execute()->first();
    return $postSaveContactArray['id'];
  }

  protected function saveEmail($cid): void {
    if (empty($this->emailEmail->get())) {
      return;
    };

    $record = $this->getSaveableFieldContents('email');

    $record['contact_id'] = $cid;
    $record['is_primary'] = TRUE;

    Email::save(FALSE)
      ->setMatch([
        'contact_id',
        'email',
      ])
      ->addRecord($record)->execute();
  }

  protected function savePhone($cid): void {
    if (empty($this->phonePhone->get())) {
      return;
    }

    $record = $this->getSaveableFieldContents('phone');
    $record['contact_id'] = $cid;
    $record['phone_type_id:name'] = 'Mobile';

    Phone::save(FALSE)
      ->setMatch([
        'contact_id',
        'phone',
      ])
      ->addRecord($record)->execute();
  }

  protected function saveAddress($cid): void {
    $addressIsEmpty =
      empty($this->addressStreetAddress->get()) &&
      empty($this->addressCity->get()) &&
      empty($this->addressPostalCode->get()) &&
      empty($this->addressStateProvinceId->get());

    if ($addressIsEmpty) {
      return;
    };

    $createValues = [
      'id' => $this->addressId->get(),
      'contact_id' => $cid,
      'street_address' => $this->addressStreetAddress->get(),
      'city' => $this->addressCity->get(),
      'postal_code' => $this->addressPostalCode->get(),
      'state_province_id' => $this->addressStateProvinceId->get(),
      'country_id' => $this->addressCountryId->get(),
      'is_primary' => TRUE,
    ];

    \Civi\Api4\Address::save(FALSE)
      ->setRecords([$createValues])
      ->setMatch([
        'street_address',
        'city',
        'postal_code',
        'state_province_id',
      ])
      ->execute();
  }

  public function updateStateAbbreviation(Field $stateIdField) {
    $abrv = $this->getAbbreviatedState($stateIdField, $this->addressCountryId);
    $this->addressStateProvinceIdAbbreviation->set($abrv ?? NULL);
  }

  protected function getAbbreviatedState($stateField, $countryField) {
    if (empty($stateId = $this->addressStateProvinceId->get())) {
      return NULL;
    }
    $countryId = $countryField->get() ?? $this->defaultCountryId();
    $abbreviationList = \CRM_Core_BAO_Address::buildOptions(
      'state_province_id',
      'abbreviate',
      ['country_id' => $countryId]
    );
    $abbreviation = $abbreviationList[$stateId] ?? NULL;
    return $abbreviation;
  }

  protected function defaultCountryId() {
    return \CRM_Core_Config::singleton()->defaultContactCountry;
  }

  protected function getContactRecordForSave(): array {
    $record = $this->getSaveableFieldContents('');
    $record['contact_type'] = 'Individual';
    $record['id'] = $this->getId();
    return $record;
  }

  protected function getSaveableFieldContents($joinName, $keepJoinName = FALSE): array {
    $record = [];
    foreach ($this->getFieldMetadata() as $camelName => $metaData) {
      if (!($select = $metaData['select'] ?? FALSE)) {
        continue;
      }
      if ($metaData['readOnly'] ?? FALSE) {
        continue;
      }
      $selectParts = explode('.', $select, 2);
      if ("$joinName" === $selectParts[0]) {
        $mungedSelect = $keepJoinName ? $select : $selectParts[1];
        $record[$mungedSelect] = $this->$camelName->get();
      }
      elseif (empty($joinName) && count($selectParts) === 1) {
        $record[$select] = $this->$camelName->get();
      }
    }
    return $record;
  }

}
