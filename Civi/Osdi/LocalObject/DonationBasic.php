<?php

namespace Civi\Osdi\LocalObject;

use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\LocalObjectInterface;
use Civi\OsdiClient;

class DonationBasic extends AbstractLocalObject implements LocalObjectInterface {

  public Field $id;
  public Field $receiveDate;
  public Field $amount;
  public Field $currency;
  public Field $financialTypeId;
  public Field $paymentInstrumentId;
  public Field $contributionRecurId;
  public Field $financialTypeLabel;
  public Field $paymentInstrumentLabel;
  public Field $contributionRecurFrequency;
  public Field $trxnId;
  public Field $source;

  protected Field $contactId;
  protected ?LocalObjectInterface $person = NULL;

  protected static function getFieldMetadata(): array {
    return [
      'id' => ['select' => 'id'],
      'amount' => ['select' => 'total_amount'],
      'receiveDate' => ['select' => 'receive_date'],
      'currency' => ['select' => 'currency'],
      'financialTypeId' => ['select' => 'financial_type_id'],
      'paymentInstrumentId' => ['select' => 'payment_instrument_id'],
      'contributionRecurId' => ['select' => 'contribution_recur_id'],
      'contactId' => ['select' => 'contact_id'],
      'trxnId' => ['select' => 'trxn_id'],
      'source' => ['select' => 'source'],
      'financialTypeLabel' => [
        'select' => 'financial_type_id:label',
        'readOnly' => TRUE,
      ],
      'paymentInstrumentLabel' => [
        'select' => 'payment_instrument_id:label',
        'readOnly' => TRUE,
      ],
      'contributionRecurFrequency' => [
        'select' => 'contribution_recur_id.frequency_unit:name',
        'readOnly' => TRUE,
      ],
    ];
  }

  public static function getCiviEntityName(): string {
    return 'Contribution';
  }

  public function getPerson(): ?LocalObjectInterface {
    return $this->person;
  }

  public function isAltered(): bool {
    if (!$this->isTouched()) {
      return FALSE;
    }
    $this->updateIdFieldsFromReferencedObjects();
    return parent::isAltered();
  }

  public function load(): self {
    parent::load();
    $this->updateReferencedObjectsFromIdFields();
    return $this;
  }

  public function loadFromArray(array $array): self {
    parent::loadFromArray($array);
    $this->updateReferencedObjectsFromIdFields();
    return $this;
  }

  public function persist(): self {
    if (is_null($p = $this->person)) {
      throw new InvalidArgumentException(
        'Unable to save %s: missing Person', __CLASS__);
    }
    if (empty($contactId = $p->getId())) {
      throw new InvalidArgumentException(
        'Person must be saved before saving %s', __CLASS__);
    }

    $orderCreateParams = $this->getOrderCreateParamsForSave();
    $orderCreateParams['contact_id'] = $contactId;
    $contributionId = (int) civicrm_api3(
      'Order', 'create', $orderCreateParams)['id'];

    // Add the payment.
    civicrm_api3('Payment', 'create', [
      'contribution_id' => $contributionId,
      'total_amount' => $this->amount->get(),
      'trxn_date' => $this->receiveDate->get(),
      'payment_instrument_id' => $this->paymentInstrumentId->get(),
      'is_send_contribution_notification' => 0,
    ]);

    $this->id->load($contributionId);
    $this->load();

    return $this;
  }

  public function setPerson(LocalObjectInterface $localPerson): self {
    if ('Contact' !== $localPerson->getCiviEntityName()) {
      throw new InvalidArgumentException();
    }
    $this->person = $localPerson;
    $this->touch();
    return $this;
  }

  protected function updateIdFieldsFromReferencedObjects() {
    $contactId = is_null($this->person) ? NULL : $this->person->getId();
    $this->contactId->set($contactId);
  }

  protected function updateReferencedObjectsFromIdFields() {
    $newContactId = $this->contactId->get();

    if (is_null($this->person) || ($newContactId !== $this->person->getId())) {
      $newPerson = OsdiClient::container()
        ->make('LocalObject', 'Person', $newContactId);
      $this->setPerson($newPerson);
    }
  }

  /**
   * Returns the Order.create API call params.
   */
  protected function getOrderCreateParamsForSave(): array {
    return [
      'receive_date' => $this->receiveDate->get(),
      'currency' => $this->currency->get(),
      'financial_type_id' => $this->financialTypeId->get(),
      'payment_instrument_id' => $this->paymentInstrumentId->get(),
      'contribution_recur_id' => $this->contributionRecurId->get(),
      'source' => $this->source->get(),
      'line_items' => [
        [
          'line_item' => [
            [
              'price_field_id' => 1,
              'price_field_value_id' => 1,
              'line_total' => $this->amount->get(),
              'unit_price' => $this->amount->get(),
              'qty' => 1,
            ],
          ],
        ],
      ],
    ];
  }

}
