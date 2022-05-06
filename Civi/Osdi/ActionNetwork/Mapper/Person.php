<?php

namespace Civi\Osdi\ActionNetwork\Mapper;

use Civi\Api4\Contact;
use Civi\Osdi\ActionNetwork\Object\Person as RemotePerson;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\LocalObject\Person as LocalPerson;

class Person {

  public function mapLocalToRemote(LocalPerson $localPerson,
      RemotePerson $remotePerson = NULL): RemotePerson {

    $l = $localPerson->loadOnce();
    $remotePerson = $remotePerson ?? new RemotePerson();

    $remotePerson->set('given_name', $l->firstName->get());
    $remotePerson->set('family_name', $l->lastName->get());
    $remotePerson->set('languages_spoken',
      [substr($l->preferredLanguage->get(), 0, 2)]);

    $noEmails = $l->isOptOut->get() || $l->doNotEmail->get();
    $remotePerson->set('email_addresses', [
      [
        'address' => $l->emailEmail->get(),
        'status' => $noEmails ? 'unsubscribed' : 'subscribed',
      ],
    ]);

    $phoneNumber = $l->phonePhoneNumeric->get();
    $noSms = $l->isOptOut->get() || $l->doNotSms->get() || empty($phoneNumber);
    $remotePerson->set('phone_numbers', [
      [
        'number' => $phoneNumber ?? '',
        'status' => $noSms ? 'unsubscribed' : 'subscribed',
      ],
    ]);

    if ($zip = $l->addressPostalCode->get()) {
      $remotePerson->set('postal_addresses', [
        [
          'address_lines' => [$l->addressStreetAddress->get()],
          'locality' => $l->addressCity->get(),
          'region' => $l->addressStateProvinceIdAbbreviation->get(),
          'postal_code' => $zip,
          'country' => $l->addressCountryIdName->get(),
        ],
      ]);
    }

    return $remotePerson;
  }

  public function mapRemoteToLocal(RemotePerson $remotePerson,
      LocalPerson $localPerson = NULL): LocalPerson {

    $localPerson = $localPerson ?? new LocalPerson();

    $localPerson->firstName->set($remotePerson->get('given_name'));
    $localPerson->lastName->set($remotePerson->get('family_name'));
    if ($mappedLanguage = $this->mapLanguageFromActionNetwork($remotePerson)) {
      $localPerson->preferredLanguageName->set($mappedLanguage);
    }

    if ($rpEmail = $remotePerson->getEmailAddress() ?? NULL) {
      $localPerson->emailEmail->set($rpEmail);
    }

    if ($rpPhone = $remotePerson->get('phone_numbers')[0]['number'] ?? NULL) {
      $localPerson->phonePhone->set($rpPhone);
    }

    if ($rpAddress = $remotePerson->get('postal_addresses')[0] ?? NULL) {
      [$stateId, $countryId]
        = $this->getStateAndCountryIdsFromActNetAddress($rpAddress);
      $localPerson->addressStreetAddress
        ->set($rpAddress['address_lines'][0] ?? '');
      $localPerson->addressCity->set($rpAddress['locality']);
      $localPerson->addressStateProvinceId->set($stateId);
      $localPerson->addressPostalCode->set($rpAddress['postal_code']);
      $localPerson->addressCountryId->set($countryId);
    }
    return $localPerson;
  }

  private function getStateAndCountryIdsFromActNetAddress(array $actNetAddress): array {
    $countryId = \CRM_Core_Config::singleton()->defaultContactCountry;
    if (isset($actNetAddress['country'])) {
      $countryIdList = \CRM_Core_BAO_Address::buildOptions(
        'country_id',
        'abbreviate'
      );
      $idFromAbbrev = array_search($actNetAddress['country'], $countryIdList);
      if ($idFromAbbrev !== FALSE) {
        $countryId = $idFromAbbrev;
      }
    }
    if (!($stateAbbrev = $actNetAddress['region'] ?? FALSE)) {
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
    if (array_key_exists($rpLanguage, $map)) {
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
