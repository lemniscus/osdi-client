<?php

namespace Civi\Osdi\ActionNetwork\Object;

use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\Exception\InvalidOperationException;
use Civi\Osdi\RemoteObjectInterface;
use Civi\OsdiClient;

class Donation extends AbstractRemoteObject implements \Civi\Osdi\RemoteObjectInterface {

  public Field $identifiers;
  public Field $createdDate;
  public Field $modifiedDate;
  public Field $amount;
  public Field $currency;
  public Field $recipients;
  public Field $payment;
  public Field $recurrence;
  public Field $fundraisingPageHref;
  public Field $donorHref;
  public Field $referrerData;

  protected ?Person $donor = NULL;

  protected ?FundraisingPage $fundraisingPage = NULL;

  protected function getFieldMetadata(): array {
    return [
      'identifiers'          => ['path' => ['identifiers'], 'appendOnly' => TRUE],
      'createdDate'          => ['path' => ['created_date'], 'createOnly' => TRUE],
      'modifiedDate'         => ['path' => ['modified_date'], 'readOnly' => TRUE],
      'amount'               => ['path' => ['amount'], 'readOnly' => TRUE],
      'currency'             => ['path' => ['currency']],
      'recipients'           => ['path' => ['recipients']],
      'payment'              => ['path' => ['payment']],
      'recurrence'           => ['path' => ['action_network:recurrence']],
      // 'donor'             => ['path' => ['action_network:person_id']],
      // 'fundraisingPageId' => ['path' => ['action_network:fundraising_page_id']],
      'donorHref'            => ['path' => ['_links', 'osdi:person'], 'createOnly' => TRUE],
      // @Todo find out what createOnly does.
      'fundraisingPageHref'  => ['path' => ['_links', 'osdi:fundraising_page'], 'createOnly' => TRUE],
      'referrerData'         => ['path' => ['action_network:referrer_data']],
      // 'referrerData'         => ['path' => ['referrer_data']],
    ];
  }

  public function getType(): string {
    return 'osdi:donations';
  }

  public function getUrlForCreate(): string {
    if (empty($this->fundraisingPage)) {
      throw new InvalidOperationException("Cannot construct URL to create a Donation without fundraisingPage.");
    }
    $fundraisingPageUrl = $this->fundraisingPage->getUrlForRead();
    return "$fundraisingPageUrl/donations";
  }

  /**
   * Override the normal one here because for everything other than create, we can access it directly.
   */
  protected function constructOwnUrl(): string {
    if (empty($id = $this->getId())) {
      throw new EmptyResultException('Cannot calculate a url for an object that has no id');
    }
    return "https://actionnetwork.org/api/v2/donations/$id";
  }

  public function getArrayForCreate(): array {
    $data = parent::getArrayForCreate();
    // Overwrite the two _links references which come through as plain strings instead of the
    // hash with ['href' => ...]
    $data['_links']['osdi:person'] = ['href' => $this->donorHref->get()];
    $data['_links']['osdi:fundraising_page'] = ['href' => $this->fundraisingPageHref->get()];
    return $data;
  }

  public function getDonor(): ?RemoteObjectInterface {
    if (empty($this->donor)) {
      $this->loadOnce();
      if (!$this->_resource->hasLink('osdi:person')) {
        return NULL;
      }
      try {
        $personResource = $this->_resource->getFirstLink('osdi:person')->get();
      }
      catch (\Jsor\HalClient\Exception\BadResponseException $e) {
        if (404 == $e->getCode()) {
          // Action Network won't let us see the donor. This is a phantom donation.
          return NULL;
        }
      }
      $this->donor = OsdiClient::container()->make(
        'OsdiObject', 'osdi:people', $this->_system, $personResource);
    }
    return $this->donor;
  }

  // @todo discuss possible use of a Trait method for this process which is shared in various places.
  public function setDonor(RemoteObjectInterface $person) {
    if ('osdi:people' !== $person->getType()) {
      throw new InvalidArgumentException();
    }
    $newPersonUrl = $this->setDonorWithoutSettingField($person);
    $this->donorHref->set($newPersonUrl);
  }

  public function setDonorWithoutSettingField(RemoteObjectInterface $person): ?string {
    $newPersonUrl = $this->getUrlIfAnyFor($person);

    // is this true of Donations? @todo
    // $currentPersonUrl = $this->donorHref->get();
    // if ($this->_id && !empty($currentPersonUrl) && ($newPersonUrl !== $currentPersonUrl)) {
      // throw new InvalidOperationException('Modifying an existing tagging on Action Network is not allowed');
    // }

    $this->donor = $person;
    return $newPersonUrl;
  }

  public function getFundraisingPage(): ?RemoteObjectInterface {
    if (empty($this->fundraisingPage)) {
      $fundraisingPageResource = $this->_resource->getFirstLink('osdi:fundraising_page')->get();
      $this->fundraisingPage = new FundraisingPage($this->_system, $fundraisingPageResource);
    }
    return $this->fundraisingPage;
  }

  // @todo discuss possible use of a Trait method for this process which is shared in various places.
  public function setFundraisingPage(RemoteObjectInterface $object) {
    if ('osdi:fundraising_pages' !== $object->getType()) {
      throw new InvalidArgumentException();
    }
    $newObjectUrl = $this->setFundraisingPageWithoutSettingField($object);
    $this->fundraisingPageHref->set($newObjectUrl);
  }

  public function setFundraisingPageWithoutSettingField(RemoteObjectInterface $object): ?string {
    $newObjectUrl = $this->getUrlIfAnyFor($object);

    // is this true of Donations? @todo
    // $currentObjectUrl = $this->fundraisingPageHref->get();
    // if ($this->_id && !empty($currentObjectUrl) && ($newObjectUrl !== $currentObjectUrl)) {
      // throw new InvalidOperationException('Modifying an existing tagging on Action Network is not allowed');
    // }

    $this->fundraisingPage = $object;
    return $newObjectUrl;
  }

  private function getUrlIfAnyFor(?RemoteObjectInterface $object): ?string {
    if (is_null($object)) {
      return NULL;
    }
    try {
      return $object->getUrlForRead();
    }
    catch (EmptyResultException $e) {
      return NULL;
    }
  }

  protected function getFieldValueForCompare(string $fieldName) {
    switch ($fieldName) {
      case 'createdDate':
        $date = $this->createdDate->get();
        return $date ? date('Y-m-d H:i:s', strtotime($date)) : NULL;

      case 'currency':
        return strtoupper($this->currency->get() ?? '');

      case 'recipients':
        $recipients = $this->recipients->get();
        $amount = $recipients[0]['amount'] ?? NULL;
        if ($amount) {
          $recipients[0]['amount'] = (float) $amount;
        }
        return $recipients;
    }
    return $this->$fieldName->get();
  }

  protected function saveWasImperfect(AbstractRemoteObject $objectBeforeSaving): bool {
    return !$this->isSupersetOf(
      $objectBeforeSaving,
      ['identifiers', 'modifiedDate', 'payment', 'referrerData']
    );
  }

}
