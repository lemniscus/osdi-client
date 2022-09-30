<?php
namespace Civi\Osdi\LocalObject;

use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\LocalObjectInterface;

class Donation extends AbstractLocalObject implements LocalObjectInterface {

  // public Field $createdDate;
  // public Field $modifiedDate;

  public Field $id;
  public Field $receiveDate;
  public Field $amount;
  public Field $currency;
  public Field $financialTypeId;
  public Field $paymentMethodId;
  public Field $contributionRecurId;
  public Field $contactId;
  // public Field $fr_page_?;
  // public Field $referrer?;
  public Field $financialTypeLabel;
  public Field $paymentMethodLabel;
  public Field $contributionRecurFrequency;
  public Field $trxnId;

  public static function getCiviEntityName(): string {
    return 'Contribution';
  }

  protected function getFieldMetadata() {
    return [
      'id'                  => ['select' => 'id'],
      'amount'              => ['select' => 'total_amount'],
      'receiveDate'         => ['select' => 'receive_date'],
      'currency'            => ['select' => 'currency'],
      'financialTypeId'     => ['select' => 'financial_type_id'], // maps to 1st (only) 'recipients'
      'paymentMethodId'     => ['select' => 'payment_method_id'], // ? from 'payment' hash?
      'contributionRecurId' => ['select' => 'contribution_recur_id'], 
      'contactId'           => ['select' => 'contact_id'],
      'trxnId'              => ['select' => 'trxn_id'],
      // 'id' => ['select' => 'id'],
      // 'fr_page_?'             => '@todo', // xxx
      // 'referrer?'             => '@todo', // xxx
      // These fields are read only
      'financialTypeLabel'   => ['select' => 'financial_type_id:label', 'readOnly' => TRUE],
      'paymentMethodLabel'   => ['select' => 'payment_method_id:label', 'readOnly' => TRUE],
      'contributionRecurFrequency' => ['select' => 'contribution_recur_id.frequency_unit:name', 'readOnly' => TRUE], 
    ];
  }

  /**
   * Create Contribution
   */
  public function save(): self {

    // Create the Contribution
    $orderCreateParams = [
      'receive_date'          => $this->receiveDate->get(),
      'currency'              => $this->currency->get(),
      'financial_type_id'     => $this->financialTypeId->get(),
      'payment_method_id'     => $this->paymentMethodId->get(),
      'contribution_recur_id' => $this->contributionRecurId->get(),
      'contact_id'            => $this->contactId->get(),
      // 'total_amount'       => $this->amount->get(),
      'line_items' => [
        [
          'line_item' => [
            'price_field_id'       => 1,
            'price_field_value_id' => 1,
            'line_total'           => $this->amount->get(),
            'unit_price'           => $this->amount->get(),
            'qty'                  => 1,
          ]
        ]
      ],
      // @todo referrer?
      // @todo fundraising page?
    ];
    $contributionId = (int) civicrm_api3('Order', 'create', $orderCreateParams)['id'];

    // Add the payment.
    civicrm_api3('Payment', 'create', [
      'contribution_id'   => $contributionId,
      'total_amount'      => $this->amount->get(),
      'trxn_date'         => $this->receiveDate->get(),
      'payment_method_id' => $this->paymentMethodId->get(),
    ]);

    $this->id->load($contributionId);
    $this->isLoaded = TRUE;

    return $this;
  }

  /**
   * Fetch the remote fundraising page ID for this donation's financial type.
   *
   * We can't do this here without a local sync state table.
   */
  public function getFundraisingPageHref() {
  }

  /**
   * Fetch the remote contact ID for this donation's.
   *
   * I'm not sure this function should be here, but I'm not sure where else to put it.
   */
  public function getPersonHref(int $syncProfileID): string {
    $contactId = $this->contactId->get();
    if (!$contactId) {
      throw new EmptyResultException('Cannot lookup a remote person match for a donation without a contact ID');
    }
    $remoteId = \Civi\Api4\OsdiPersonSyncState::get(FALSE)
      ->addSelect('remote_person_id')
      ->addWhere('contact_id', '=', $contactId)
      ->addWhere('sync_profile_id', '=', $syncProfileID)
      ->execute()->single()['id'] ?? NULL;
    if (!$remoteId) {
      throw new EmptyResultException("Contact $contactId is not in sync with the remote service, so cannot post a donation belonging to that contact.");
    }

    return $remoteId;
  }
}
