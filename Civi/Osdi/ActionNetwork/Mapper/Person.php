<?php

namespace Civi\Osdi\ActionNetwork\Mapper;

use Civi\Api4\Contact;
use Civi\Osdi\ActionNetwork\Object\Person as RemotePerson;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\RemoteSystemInterface;

class Person {

  /**
   * @var \Civi\Osdi\RemoteSystemInterface
   */
  private $system;

  /**
   * Generic constructor.
   */
  public function __construct(RemoteSystemInterface $system) {
    $this->system = $system;
  }

  public function mapContactOntoRemotePerson(int $id, RemotePerson $remotePerson = NULL): RemotePerson {
    $c = $this->getSingleCiviContactById($id);
    if (is_null($remotePerson)) {
      $remotePerson = new RemotePerson();
    }
    $remotePerson->set('given_name', $c['first_name']);
    $remotePerson->set('family_name', $c['last_name']);
    if (isset($c['address.postal_code'])) {
      $remotePerson->set('postal_addresses', [
        [
          'address_lines' => [$c['address.street_address']],
          'locality' => $c['address.city'],
          'region' => $c['address.state_province_id:abbreviation'],
          'postal_code' => $c['address.postal_code'],
          'country' => $c['address.country_id:name'],
        ],
      ]);
    }
    $noEmails = $c['is_opt_out'] || $c['do_not_email'];
    $remotePerson->set('email_addresses', [
      [
        'address' => $c['email.email'],
        'status' => $noEmails ? 'unsubscribed' : 'subscribed',
      ],
    ]);
    $phoneNumber = $c['phone.phone_numeric'];
    $noSms = $c['is_opt_out'] || $c['do_not_sms'] || empty($phoneNumber);
    $remotePerson->set('phone_numbers', [
      [
        'number' => $phoneNumber ?? '',
        'status' => $noSms ? 'unsubscribed' : 'subscribed',
      ],
    ]);
    $remotePerson->set('languages_spoken', [substr($c['preferred_language'], 0, 2)]);
    return $remotePerson;
  }

  /**
   * @return \Civi\Api4\Generic\AbstractCreateAction|\Civi\Api4\Generic\AbstractUpdateAction
   */
  public function mapRemotePersonOntoContact(RemotePerson $remotePerson, int $contactId = NULL) {
    if ($contactId) {
      $apiAction = Contact::update()->addWhere('id', '=', $contactId);
    }
    else {
      $apiAction = Contact::create();
    }
    $apiAction->setValues(
      [
        'first_name' => $remotePerson->get('given_name'),
        'last_name' => $remotePerson->get('family_name'),
      ]
    );

    if ($mappedLanguage = $this->mapLanguageFromActionNetwork($remotePerson)) {
      $apiAction->addValue('preferred_language:name', $mappedLanguage);
    }
    $rpEmail = $remotePerson->get('email_addresses')[0]['address'] ?? NULL;
    if ($rpEmail) {
      $emailCreate = \Civi\Api4\Email::create()->setValues(
        [
          'contact_id' => '$id',
          'email' => $rpEmail,
        ]
      );
      $apiAction->addChain('email', $emailCreate);
    }
    $rpPhone = $remotePerson->get('phone_numbers')[0]['number'] ?? NULL;
    if ($rpPhone) {
      $phoneCreate = \Civi\Api4\Phone::create()->setValues(
        [
          'contact_id' => '$id',
          'phone' => $rpPhone,
        ]
      );
      $apiAction->addChain('phone', $phoneCreate);
    }
    $rpAddress = $remotePerson->get('postal_addresses')[0] ?? NULL;
    if ($rpAddress) {
      [$stateId, $countryId]
        = $this->getStateAndCountryIdsFromOsdiAddress($rpAddress);
      $addressCreate = \Civi\Api4\Address::create()->setValues(
        [
          'contact_id' => '$id',
          'street_address' => $rpAddress['address_lines'][0] ?? '',
          'city' => $rpAddress['locality'],
          'state_province_id' => $stateId,
          'postal_code' => $rpAddress['postal_code'],
          'country_id' => $countryId,
        ]
      );
      $apiAction->addChain('address', $addressCreate);
    }
    return $apiAction;
  }

  /**
   * @return array{select: array, join: array}
   */
  public function getFieldsToSelect(): array {
    return [
      'select' => [
        'id',
        'modified_date',
        'first_name',
        'last_name',
        'is_opt_out',
        'do_not_email',
        'do_not_sms',
        'preferred_language',
        'email.email',
        'phone.phone_numeric',
        'address.street_address',
        'address.city',
        'address.state_province_id',
        'address.postal_code',
        'address.country_id',
        'address.country_id:name',
      ],
      'join' => [
        ['Email AS email', 'LEFT', NULL, ['email.is_primary', '=', 1]],
        ['Phone AS phone', FALSE, NULL, ['phone.phone_type_id:name', '=', '"Mobile"']],
        ['Address AS address', FALSE, NULL, ['address.is_primary', '=', 1]],
      ],
    ];
  }

  public function getSingleCiviContactById($id): array {
    $clauses = $this->getFieldsToSelect();
    $result = Contact::get(FALSE)
      ->setJoin($clauses['join'])
      ->setSelect($clauses['select'])
      ->setWhere([
        ['id', '=', $id],
        ['is_deleted', '=', FALSE],
      ])
      ->execute();
    if (!$result->count()) {
      throw new InvalidArgumentException('Unable to retrieve contact id %d', $id);
    }
    $result = $result->single();
    $result['address.state_province_id:abbreviation'] = NULL;
    if (isset($result['address.state_province_id'])) {
      $abbreviation = $this->getAbbreviatedState($result);
      $result['address.state_province_id:abbreviation'] = $abbreviation;
    }
    return $result;
  }

  /**
   * @param array $contactData
   *
   * @return mixed
   */
  private function getAbbreviatedState(array $contactData) {
    $countryId = $contactData['address.country_id'] ?? \CRM_Core_Config::singleton()->defaultContactCountry;
    $abbreviationList = \CRM_Core_BAO_Address::buildOptions(
      'state_province_id',
      'abbreviate',
      ['country_id' => $countryId]
    );
    $abbreviation = $abbreviationList[$contactData['address.state_province_id']];
    return $abbreviation;
  }

  private function getStateAndCountryIdsFromOsdiAddress(array $osdiAddress): array {
    $countryId = \CRM_Core_Config::singleton()->defaultContactCountry;
    if (isset($osdiAddress['country'])) {
      $countryIdList = \CRM_Core_BAO_Address::buildOptions(
        'country_id',
        'abbreviate'
      );
      $idFromAbbrev = array_search($osdiAddress['country'], $countryIdList);
      if ($idFromAbbrev !== FALSE) {
        $countryId = $idFromAbbrev;
      }
    }
    if (!($stateAbbrev = $osdiAddress['region'] ?? FALSE)) {
      return [NULL, $countryId];
    }
    $stateAbbrevList = \CRM_Core_BAO_Address::buildOptions(
      'state_province_id',
      'abbreviate',
      ['country_id' => $countryId]
    );
    $stateId = array_search($stateAbbrev, $stateAbbrevList);
    if ($stateId === FALSE) {
      $stateId = NULL;
    }
    return [$stateId, $countryId];
  }

  public function mapLanguageFromActionNetwork(RemotePerson $remotePerson): ?string {
    if (!($rpLanguages = $remotePerson->get('languages_spoken'))) {
      return NULL;
    }
    $rpLanguage = $rpLanguages[0];
    $map = ['en' => 'en_US', 'es' => 'es_MX'];
    if (in_array($rpLanguage, $map)) {
      return $map[$rpLanguage];
    }
    $civiLangs = \CRM_Contact_BAO_Contact::buildOptions('preferred_language', 'create');
    foreach ($civiLangs as $isoCode => $label) {
      if ($rpLanguage === substr($isoCode, 0, 2)) {
        return $isoCode;
      }
    }
    return NULL;
  }

}
