<?php

namespace Civi\Osdi\ActionNetwork\Mapper;

use Civi\Api4\Contact;
use Civi\Api4\Generic\AbstractAction;
use Civi\Osdi\ActionNetwork\OsdiPerson;
use Civi\Osdi\RemoteSystemInterface;

class Example {

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

  public function mapContactOntoRemotePerson(int $id, OsdiPerson $remotePerson = NULL): OsdiPerson {
    $c = $this->getSingleCiviContactById($id);
    if (is_null($remotePerson)) {
      $remotePerson = new OsdiPerson();
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
    $remotePerson->set('email_addresses', [['address' => $c['email.email']]]);
    $remotePerson->set('phone_numbers', [['number' => $c['phone.phone_numeric']]]);
    $remotePerson->set('languages_spoken', [substr($c['preferred_language'], 0, 2)]);
    return $remotePerson;
  }

  public function mapRemotePersonOntoContact(OsdiPerson $remotePerson, int $contactId = NULL): AbstractAction {
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

  private function getSingleCiviContactById($id): array {
    $result = Contact::get(FALSE)
      ->addJoin('Email AS email', FALSE, NULL, ['email.is_primary', '=', 1])
      ->addJoin('Phone AS phone', FALSE, NULL, [
        'phone.phone_type_id:name',
        '=',
        '"Mobile"',
      ])
      ->addJoin('Address AS address', FALSE, NULL, [
        'address.is_primary',
        '=',
        1,
      ])
      ->setSelect([
        'id',
        'first_name',
        'last_name',
        'preferred_language',
        'email.email',
        'phone.phone_numeric',
        'address.street_address',
        'address.city',
        'address.state_province_id',
        'address.postal_code',
        'address.country_id',
        'address.country_id:name',
      ])
      ->setWhere([
        ['id', '=', $id],
        ['is_deleted', '=', FALSE],
      ])
      ->execute();
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
    if (!($stateAbbrev = $osdiAddress['region'])) {
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

  public function mapLanguageFromActionNetwork(OsdiPerson $remotePerson): ?string {
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
