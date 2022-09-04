<?php

namespace Civi\Osdi\ActionNetwork\Object;

use Civi\Osdi\Exception\InvalidOperationException;
use Civi\Osdi\Result\Save;
use Civi\Osdi\Result\Save as SaveResult;

class Person extends AbstractRemoteObject implements \Civi\Osdi\RemoteObjectInterface {

  public Field $identifiers;
  public Field $createdDate;
  public Field $modifiedDate;
  public Field $givenName;
  public Field $familyName;
  public Field $emailAddress;
  public Field $emailStatus;
  public Field $phoneNumber;
  public Field $phoneStatus;
  public Field $postalStreet;
  public Field $postalLocality;
  public Field $postalRegion;
  public Field $postalCode;
  public Field $postalCountry;
  public Field $languageSpoken;
  public Field $customFields;

  const FIELDS = [
    'identifiers' => ['path' => ['identifiers'], 'appendOnly' => TRUE],
    'createdDate' => ['path' => ['created_date'], 'readOnly' => TRUE],
    'modifiedDate' => ['path' => ['modified_date'], 'readOnly' => TRUE],
    'givenName' => ['path' => ['given_name'], 'mungeNulls' => TRUE],
    'familyName' => ['path' => ['family_name'], 'mungeNulls' => TRUE],
    'emailAddress' => ['path' => ['email_addresses', '0', 'address']],
    'emailStatus' => ['path' => ['email_addresses', '0', 'status']],
    'phoneNumber' => ['path' => ['phone_numbers', '0', 'number']],
    'phoneStatus' => ['path' => ['phone_numbers', '0', 'status']],
    'postalStreet' => [
      'path' => ['postal_addresses', '0', 'address_lines', '0'],
      'mungeNulls' => TRUE,
    ],
    'postalLocality' => [
      'path' => ['postal_addresses', '0', 'locality'],
      'mungeNulls' => TRUE,
    ],
    'postalRegion' => [
      'path' => ['postal_addresses', '0', 'region'],
      'mungeNulls' => TRUE,
    ],
    'postalCode' => [
      'path' => ['postal_addresses', '0', 'postal_code'],
      'mungeNulls' => TRUE,
    ],
    'postalCountry' => [
      'path' => ['postal_addresses', '0', 'country'],
      'mungeNulls' => TRUE,
    ],
    'languageSpoken' => ['path' => ['languages_spoken', '0']],
    'customFields' => ['path' => ['custom_fields']],
  ];

  public function getType(): string {
    return 'osdi:people';
  }

  public function getArrayForCreate(): array {
    if (empty($this->emailStatus->get()) && !empty($this->emailAddress->get())) {
      $this->emailStatus->set('subscribed');
    }
    if (empty($this->phoneStatus->get()) && !empty($this->phoneNumber->get())) {
      $this->phoneStatus->set('subscribed');
    }

    return ['person' => parent::getArrayForCreate()];
  }

  public function delete() {
    if (!$this->getId()) {
      throw new InvalidOperationException('Cannot delete person without id');
    }

    /*
     * This is as close as we can get to deleting someone through the AN API.
     *
     * We leave the email address and phone number untouched, because we aren't
     * allowed to truly delete them, and overwriting them with the null char
     * likely won't have the effect we want due to AN's deduplication rules,
     * https://help.actionnetwork.org/hc/en-us/articles/360038822392-Deduplicating-activists-on-Action-Network
     */

    $this->emailStatus->set('unsubscribed');
    $this->phoneStatus->set('unsubscribed');
    $this->givenName->set(NULL);
    $this->familyName->set(NULL);
    $this->languageSpoken->set('en');
    $this->postalStreet->set(NULL);
    $this->postalCode->set(NULL);
    $this->postalRegion->set(NULL);
    $this->postalCountry->set(NULL);

    $this->save();
  }

  public function getUrlForCreate(): string {
    return 'https://actionnetwork.org/api/v2/people';
  }

  public function checkForEmailAddressConflict(): array {
    if (!($this->getId() && $this->emailAddress->isAltered())) {
      return [NULL, NULL, NULL];
    }

    $criteria = [['email', 'eq', $this->emailAddress->get()]];
    $peopleWithTheEmail = $this->_system->find($this->getType(), $criteria);

    if (0 == $peopleWithTheEmail->rawCurrentCount()) {
      return [NULL, NULL, NULL];
    }

    if ($this->emailAddress->get() !==
      $peopleWithTheEmail->rawFirst()->emailAddress->get()) {
      throw new \Exception('Unexpected response from Action Network');
    }

    if ($this->getId() === $peopleWithTheEmail->rawFirst()->getId()) {
      return [NULL, NULL, NULL];
    }

    $statusCode = Save::ERROR;
    $statusMessage = 'The person cannot be saved because '
      . 'there is a record on Action Network with the same '
      . 'email address and a different ID.';
    $context = [
      'object' => $this->getAll(),
      'conflictingObject' => $peopleWithTheEmail->rawFirst()->getAll(),
    ];
    return [$statusCode, $statusMessage, $context];
  }

  protected function getFieldValueForCompare(string $fieldName) {
    switch ($fieldName) {
      case 'phoneNumber':
        return self::normalizePhoneNumber($this->phoneNumber->get());

      case 'emailAddress':
      case 'postalLocality':
        return strtolower($this->$fieldName->get());
    }
    return $this->$fieldName->get();
  }

  public static function normalizePhoneNumber(?string $phoneNumber = ''): string {
    $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
    $phoneNumber = preg_replace('/^1(\d{10})$/', '$1', $phoneNumber);
    $phoneNumber = preg_replace('/^(\d{3})(\d{3})(\d{4})$/', '($1) $2-$3', $phoneNumber);
    return $phoneNumber;
  }

  public function trySave(): Save {
    [$statusCode, $statusMessage, $context] = $this->checkForEmailAddressConflict();

    if ($statusCode === SaveResult::ERROR) {
      return new SaveResult($this, $statusCode, $statusMessage, $context);
    }

    return parent::trySave();
  }

}
