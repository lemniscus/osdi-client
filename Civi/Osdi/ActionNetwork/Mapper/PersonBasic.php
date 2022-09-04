<?php

namespace Civi\Osdi\ActionNetwork\Mapper;

use Civi\Osdi\ActionNetwork\Object\Person as RemotePerson;
use Civi\Osdi\LocalObject\PersonBasic as LocalPerson;
use Civi\Osdi\LocalObjectInterface;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\MapperInterface;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\Result\Map as MapResult;

class PersonBasic implements MapperInterface {

  private RemoteSystemInterface $remoteSystem;

  public function __construct(RemoteSystemInterface $remoteSystem) {
    $this->remoteSystem = $remoteSystem;
  }

  public function mapOneWay(LocalRemotePair $pair): MapResult {
    $result = new MapResult();

    try {
      if ($pair->isOriginLocal()) {
        $this->mapLocalToRemote($pair->getLocalObject(), $pair->getRemoteObject());
      }
      else {
        $this->mapRemoteToLocal($pair->getRemoteObject(), $pair->getLocalObject());
      }
      $result->setStatusCode($result::SUCCESS);
    }
    catch (\Throwable $exception) {
      $result->setStatusCode($result::ERROR);
    }

    $pair->getResultStack()->push($result);
    return $result;
  }

  public function mapLocalToRemote(
    LocalObjectInterface $localPerson,
    RemoteObjectInterface $remotePerson = NULL
  ): RemotePerson {

    $l = $localPerson->loadOnce();
    $remotePerson = $remotePerson ?? new RemotePerson($this->remoteSystem);

    $remotePerson->givenName->set($l->firstName->get());
    $remotePerson->familyName->set($l->lastName->get());
    $remotePerson->languageSpoken->set(substr($l->preferredLanguage->get(), 0, 2));

    $noEmails = $l->isOptOut->get() || $l->doNotEmail->get();
    $remotePerson->emailAddress->set($l->emailEmail->get());
    $remotePerson->emailStatus->set($noEmails ? 'unsubscribed' : 'subscribed');

    $phoneNumber = $l->phonePhoneNumeric->get();
    $noSms = $l->isOptOut->get() || $l->doNotSms->get() || empty($phoneNumber);
    $remotePerson->phoneNumber->set($phoneNumber ?? '');
    $remotePerson->phoneStatus->set($noSms ? 'unsubscribed' : 'subscribed');

    if ($zip = $l->addressPostalCode->get()) {
      $remotePerson->postalStreet->set($l->addressStreetAddress->get());
      $remotePerson->postalLocality->set($l->addressCity->get());
      $remotePerson->postalRegion->set($l->addressStateProvinceIdAbbreviation->get());
      $remotePerson->postalCode->set($zip);
      $remotePerson->postalCountry->set($l->addressCountryIdName->get());
    }

    return $remotePerson;
  }

  public function mapRemoteToLocal(
    RemoteObjectInterface $remotePerson,
    LocalObjectInterface $localPerson = NULL
  ): LocalPerson {

    $localPerson = $localPerson ?? new LocalPerson();

    $localPerson->firstName->set($remotePerson->givenName->get());
    $localPerson->lastName->set($remotePerson->familyName->get());
    if ($mappedLanguage = $this->mapLanguageFromActionNetwork($remotePerson)) {
      $localPerson->preferredLanguageName->set($mappedLanguage);
    }

    if ($rpEmail = $remotePerson->emailAddress->get()) {
      $localPerson->emailEmail->set($rpEmail);
    }

    if ($rpPhone = $remotePerson->phoneNumber->get()) {
      $localPerson->phonePhone->set($rpPhone);
    }

    if ($zip = $remotePerson->postalCode->get()) {
      [$stateId, $countryId]
        = $this->getStateAndCountryIdsFromActNetAddress($remotePerson);
      $localPerson->addressStreetAddress->set($remotePerson->postalStreet->get());
      $localPerson->addressCity->set($remotePerson->postalLocality->get());
      $localPerson->addressStateProvinceId->set($stateId);
      $localPerson->addressPostalCode->set($zip);
      $localPerson->addressCountryId->set($countryId);
    }
    return $localPerson;
  }

  private function getStateAndCountryIdsFromActNetAddress(RemotePerson $person): array {
    $countryId = \CRM_Core_Config::singleton()->defaultContactCountry;
    if (!empty($actNetCountry = $person->postalCountry->get())) {
      $countryIdList = \CRM_Core_BAO_Address::buildOptions(
        'country_id',
        'abbreviate'
      );
      $idFromAbbrev = array_search($actNetCountry, $countryIdList);
      if ($idFromAbbrev !== FALSE) {
        $countryId = $idFromAbbrev;
      }
    }
    if (empty($stateAbbrev = $person->postalRegion->get())) {
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
    if (empty($rpLanguage = $remotePerson->languageSpoken->get())) {
      return NULL;
    }
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
