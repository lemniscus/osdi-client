<?php

namespace Civi\Osdi\LocalObject;

use Civi\Api4\Address;
use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\Phone;
use Civi\Osdi\Exception\InvalidArgumentException;

class Person implements LocalObjectInterface {

  public Field $id;
  public Field $createdDate;
  public Field $modifiedDate;
  public Field $firstName;
  public Field $lastName;
  public Field $isOptOut;
  public Field $doNotEmail;
  public Field $doNotSms;
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

  private bool $isLoaded = FALSE;
  private bool $isTouched = FALSE;

  const FIELDS = [
    'id' => ['select' => 'id'],
    'createdDate' => ['select' => 'created_date', 'readOnly' => TRUE],
    'modifiedDate' => ['select' => 'modified_date', 'readOnly' => TRUE],
    'firstName' => ['select' => 'first_name'],
    'lastName' => ['select' => 'last_name'],
    'isOptOut' => ['select' => 'is_opt_out'],
    'doNotEmail' => ['select' => 'do_not_email'],
    'doNotSms' => ['select' => 'do_not_sms'],
    'preferredLanguage' => ['select' => 'preferred_language'],
    'preferredLanguageName' => ['select' => 'preferred_language:name'],
    'emailId' => ['select' => 'email.id'],
    'emailEmail' => ['select' => 'email.email'],
    'phoneId' => ['select' => 'phone.id'],
    'phonePhone' => ['select' => 'phone.phone'],
    'phonePhoneNumeric' => [
      'select' => 'phone.phone_numeric',
      'readOnly' => TRUE,
    ],
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

  const JOINS = [
    ['Email AS email', 'LEFT', NULL, ['email.is_primary', '=', 1]],
    ['Phone AS phone', 'LEFT', NULL,
      ['phone.phone_type_id:name', '=', '"Mobile"'],
    ],
    ['Address AS address', FALSE, NULL, ['address.is_primary', '=', 1]],
  ];

  protected array $newData = [];

  protected array $originalData = [];

  public function __construct(int $idValue = NULL) {
    foreach (self::FIELDS as $name => $metadata) {
      $options = array_merge($metadata, ['bundle' => $this]);
      $this->$name = new Field($name, $options);
    }
    if ($idValue) {
      $this->id->load($idValue);
    }
  }

  public function getId(): ?int {
    return $this->id->get();
  }

  public function setId(int $value) {
    $this->id->set($value);
  }

  public function getAll(): array {
    $this->loadOnce();
    return $this->getAllWithoutLoading();
  }

  public function getAllWithoutLoading(): array {
    $return = [];
    foreach (static::FIELDS as $fieldName => $x) {
      $return[$fieldName] = $this->$fieldName->get();
    }
    return $return;
  }

  public function getAllLoaded(): array {
    $this->loadOnce();
    $return = [];
    foreach (static::FIELDS as $fieldName => $x) {
      $return[$fieldName] = $this->$fieldName->getLoaded();
    }
    return $return;
  }

  public static function isValidField(string $name): bool {
    return array_key_exists($name, static::FIELDS);
  }

  public function isTouched(): bool {
    return $this->isTouched;
  }

  public function isAltered(): bool {
    if (!$this->isTouched) {
      return FALSE;
    }
    $this->loadOnce();
    foreach (static::FIELDS as $fieldName => $x) {
      if ($this->$fieldName->isAltered()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  public function isLoaded(): bool {
    return $this->isLoaded;
  }

  public function delete(): ?\Civi\Api4\Generic\Result {
    if (empty($id = $this->getId())) {
      return NULL;
    }
    return Contact::delete(FALSE)
      ->addWhere('id', '=', $id)
      ->execute();
  }

  public function load(): Person {
    $this->isLoaded = FALSE;

    if (!($id = $this->getId())) {
      throw new InvalidArgumentException('%s::%s requires the %s to have an id',
        static::class, __FUNCTION__, static::class);
    }

    foreach (static::FIELDS as $camelName => $fieldMetaData) {
      if (array_key_exists('select', $fieldMetaData)) {
        $selects[$fieldMetaData['select']] = $camelName;
      }
    }

    $result = Contact::get(FALSE)
      ->setJoin(static::JOINS)
      ->setOrderBy([
        'email.id' => 'ASC',
        'phone.id' => 'ASC',
        'address.id' => 'ASC',
      ])
      ->setSelect(array_keys($selects))
      ->setWhere([
        ['id', '=', $id],
        ['is_deleted', '=', FALSE],
      ])
      ->execute();

    if (!$result->count()) {
      throw new InvalidArgumentException('Unable to retrieve contact id %d', $id);
    }

    $result = $result->last();

    foreach ($result as $key => $val) {
      /** @var \Civi\Osdi\LocalObject\Field $field */
      $field = $this->{$selects[$key]};
      $field->load($val);
    }

    $abrv = $this->getAbbreviatedState(
      $this->addressStateProvinceId,
      $this->addressCountryId
    );
    $this->addressStateProvinceIdAbbreviation->load($abrv ?? NULL);

    $this->isLoaded = TRUE;
    return $this;
  }

  public function loadOnce(): Person {
    if (!$this->isLoaded()) {
      return $this->load();
    }
    return $this;
  }

  public function touch() {
    $this->isTouched = TRUE;
  }

  public function save(): Person {
    $cid = Contact::save(FALSE)->addRecord([
      'id' => $this->getId(),
      'first_name' => $this->firstName->get(),
      'last_name' => $this->lastName->get(),
      'is_opt_out' => $this->isOptOut->get(),
      'do_not_email' => $this->doNotEmail->get(),
      'do_not_sms' => $this->doNotSms->get(),
      'preferred_language' => $this->preferredLanguage->get(),
      'preferred_language:name' => $this->preferredLanguageName->get(),
    ])->execute()->first()['id'];

    $this->id->load($cid);

    if (!empty($this->emailEmail->get())) {
      $this->saveEmail($cid);
    };

    if (!empty($this->phonePhone->get())) {
      $this->savePhone($cid);
    }

    $addressIsEmpty =
      empty($this->addressStreetAddress->get()) &&
      empty($this->addressCity->get()) &&
      empty($this->addressPostalCode->get()) &&
      empty($this->addressStateProvinceId->get());

    if (!$addressIsEmpty) {
      $this->saveAddress($cid);
    };

    return $this;
  }

  private function saveEmail($cid): void {
    Email::save(FALSE)
      ->setMatch([
        'contact_id',
        'email',
      ])
      ->addRecord([
        'id' => $this->emailId->get(),
        'contact_id' => $cid,
        'email' => $this->emailEmail->get(),
        'is_primary' => TRUE,
      ])->execute();
  }

  private function savePhone($cid): void {
    Phone::save(FALSE)
      ->setMatch([
        'contact_id',
        'phone',
      ])
      ->addRecord([
        'id' => $this->phoneId->get(),
        'contact_id' => $cid,
        'phone' => $this->phonePhone->get(),
        'phone_type_id:name' => 'Mobile',
      ])->execute();
  }

  private function saveAddress($cid): void {
    if (!$this->addressId->get()) {
      $addressMatchGet = Address::get(FALSE)
        ->addWhere('contact_id', '=', $cid);

      $matchFields = [
        'addressStreetAddress',
        'addressCity',
        'addressPostalCode',
        'addressStateProvinceId',
      ];
      foreach ($matchFields as $name) {
        $val = $this->$name->get();
        $op = is_null($val) ? 'IS NULL' : '=';
        $dbName = substr(self::FIELDS[$name]['select'], 8);
        $addressMatchGet->addWhere($dbName, $op, $val);
      }

      $this->addressId->set($addressMatchGet->execute()->last()['id'] ?? NULL);
    }

    Address::save(FALSE)
      ->addRecord([
        'id' => $this->addressId->get(),
        'contact_id' => $cid,
        'street_address' => $this->addressStreetAddress->get(),
        'city' => $this->addressCity->get(),
        'postal_code' => $this->addressPostalCode->get(),
        'state_province_id' => $this->addressStateProvinceId->get(),
        'country_id' => $this->addressCountryId->get(),
      ])->execute();
  }

  public function updateStateAbbreviation(Field $stateIdField) {
    $abrv = $this->getAbbreviatedState($stateIdField, $this->addressCountryId);
    $this->addressStateProvinceIdAbbreviation->set($abrv ?? NULL);
  }

  private function getAbbreviatedState($stateField, $countryField) {
    if (empty($stateId = $this->addressStateProvinceId->get())) {
      return NULL;
    }
    $countryId = $countryField->get() ?? $this->defaultCountryId();
    $abbreviationList = \CRM_Core_BAO_Address::buildOptions(
      'state_province_id',
      'abbreviate',
      ['country_id' => $countryId]
    );
    $abbreviation = $abbreviationList[$stateId];
    return $abbreviation;
  }

  private function defaultCountryId() {
    return \CRM_Core_Config::singleton()->defaultContactCountry;
  }

}
