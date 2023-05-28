<?php

namespace Civi\Osdi\LocalObject;

use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\LocalObjectInterface;

/**
 * not implemented:
 * public Field $createdDate;
 * public Field $modifiedDate;
 * public Field $fr_page_?;
 * public Field $referrer?;
 */
class DonationBasic extends AbstractLocalObject implements LocalObjectInterface {

  public Field $id;
  public Field $receiveDate;
  public Field $amount;
  public Field $currency;
  public Field $financialTypeId;
  public Field $paymentInstrumentId;
  public Field $contributionRecurId;
  public Field $contactId;
  public Field $financialTypeLabel;
  public Field $paymentInstrumentLabel;
  public Field $contributionRecurFrequency;
  public Field $trxnId;
  public Field $source;

  public static function getCiviEntityName(): string {
    return 'Contribution';
  }

  /**
   * Fetch the remote fundraising page ID for this donation's financial type.
   *
   * We can't do this here without a local sync state table.
   */
  public function getFundraisingPageHref() {
    throw new \RuntimeException(__CLASS__ . '::' . __FUNCTION__ . ' not implemented');
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
      'contact_id' => $this->contactId->get(),
      'source' => $this->source->get(),
      // 'total_amount'       => $this->amount->get(),
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
      // @todo referrer?
    ];
  }

  /**
   * Fetch the remote person ID for this donation.
   *
   * I'm not sure this function should be here, but I'm not sure where else to
   * put it.
   *
   * @todo remove this if it remains unused
   */
  public function getPersonHref(int $syncProfileID): string {
    $contactId = $this->contactId->get();
    if (!$contactId) {
      throw new EmptyResultException('Cannot lookup a remote person '
        . 'match for a donation without a contact ID');
    }
    $remoteId = \Civi\Api4\OsdiPersonSyncState::get(FALSE)
      ->addSelect('remote_person_id')
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('sync_profile_id', '=', $syncProfileID)
      ->execute()->single()['id'] ?? NULL;
    if (!$remoteId) {
      throw new EmptyResultException("Contact $contactId is not in sync'
      . ' with the remote service, so cannot post a donation belonging to that contact.");
    }

    return $remoteId;
  }

  public function persist(): \Civi\Osdi\CrudObjectInterface {
    throw new \RuntimeException("persist called, but not programmed");
    return $this;
  }

  /**
   * Create Contribution
   */
  public function save(): self {

    // Create the Contribution
    $orderCreateParams = $this->getOrderCreateParamsForSave();
    $contributionId = (int) civicrm_api3('Order', 'create', $orderCreateParams)['id'];

    // Add the payment.
    civicrm_api3('Payment', 'create', [
      'contribution_id' => $contributionId,
      'total_amount' => $this->amount->get(),
      'trxn_date' => $this->receiveDate->get(),
      'payment_instrument_id' => $this->paymentInstrumentId->get(),
      'is_send_contribution_notification' => 0,
    ]);

    $this->id->load($contributionId);
    $this->isLoaded = TRUE;

    return $this;
  }

  protected function getFieldMetadata() {
    return [
      'id' => ['select' => 'id'],
      'amount' => ['select' => 'total_amount'],
      'receiveDate' => ['select' => 'receive_date'],
      'currency' => ['select' => 'currency'],
      'financialTypeId' => ['select' => 'financial_type_id'],
      // maps to 1st (only) 'recipients'
      'paymentInstrumentId' => ['select' => 'payment_instrument_id'],
      // ? from 'payment' hash?
      'contributionRecurId' => ['select' => 'contribution_recur_id'],
      'contactId' => ['select' => 'contact_id'],
      'trxnId' => ['select' => 'trxn_id'],
      'source' => ['select' => 'source'],
      // 'id' => ['select' => 'id'],
      // 'fr_page_?'             => '@todo', // xxx
      // 'referrer?'             => '@todo', // xxx
      // These fields are read only
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
      'tceFundraisingPage' => ['select' => ''],
    ];
  }

}
