<?php

namespace Civi\Osdi\ActionNetwork\Object;

use Civi\Osdi\Exception\InvalidOperationException;

class Donation extends AbstractRemoteObject implements \Civi\Osdi\RemoteObjectInterface {

  public Field $identifiers;
  public Field $createdDate;
  public Field $modifiedDate;
  public Field $amount;
  public Field $currency;
  public Field $recipients;
  public Field $payment;
  public Field $recurrence;
  public Field $donor;
  public Field $fundraisingPageId;
  public Field $referrerData;

  protected function getFieldMetadata(): array {
    return [
      'identifiers'       => ['path' => ['identifiers'], 'appendOnly' => TRUE],
      'createdDate'       => ['path' => ['created_date'], 'readOnly' => TRUE],
      'modifiedDate'      => ['path' => ['modified_date'], 'readOnly' => TRUE],
      'amount'            => ['path' => ['amount'], 'readOnly' => TRUE],
      'currency'          => ['path' => ['currency']],
      'recipients'        => ['path' => ['recipients']],
      'payment'           => ['path' => ['payment']],
      'recurrence'        => ['path' => ['action_network:recurrence']],
      // 'donor'             => ['path' => ['action_network:person_id']],
      // 'fundraisingPageId' => ['path' => ['action_network:fundraising_page_id']],
      'donor'             => ['path' => ['_links', 'osdi:person'], 'createOnly' => TRUE], // @Todo find out what createOnly does.
      'fundraisingPage'   => ['path' => ['_links', 'osdi:fundraising_page'], 'createOnly' => TRUE],
      'referrerData'      => ['path' => ['action_network:referrer_data']],
    ];
  }

  public function getType(): string {
    return 'osdi:donations';
  }

  public function getUrlForCreate(): string {
    $fundraisingPageId = $this->fundraisingPageId->get();
    if (empty($fundraisingPageId)) {
      throw new InvalidOperationException("Cannot construct URL to create a Donation without fundraisingPageId being set.");
    }

    $fundraisingPage = new FundraisingPage($this->_system);
    $fundraisingPage->setId($fundraisingPageId);
    $fundraisingPageUrl = $fundraisingPage->getUrlForRead();
    return "$fundraisingPageUrl/donations";
  }

  public function getArrayForCreate(): array {
    $data = parent::getArrayForCreate();
    unset($data['identifiers']);
    unset($data['created_date']);
    unset($data['modified_date']);
    unset($data['amount']);
    $data['_links']['osdi:person'] = ['href' => ''];
    return $data;
  }
}

