<?php

namespace OsdiClient\ActionNetwork;

use API_Exception;
use PHPUnit\Framework\TestCase;

class PersonMatchingFixture {

  public static string $personClass;

  public static \Civi\Osdi\RemoteSystemInterface $remoteSystem;

  /**
   * @param $address
   *
   * @return \Civi\Api4\Generic\Result
   * @throws API_Exception
   * @throws \Civi\API\Exception\NotImplementedException
   */
  public static function civiApi4GetContactByEmail($address): \Civi\Api4\Generic\Result {
    return civicrm_api4(
      'Contact',
      'get',
      [
        'select' => [
          'row_count',
        ],
        'join' => [
          ['Email AS email', TRUE],
        ],
        'where' => [
          ['email.email', '=', $address],
          ['email.is_primary', '=', TRUE],
          ['is_deleted', '=', FALSE],
        ],
        'checkPermissions' => FALSE,
      ]
    );
  }

  public static function civiApi4GetSingleContactById($id): array {
    return civicrm_api4(
      'Contact',
      'get',
      [
        'select' => [
          'id',
          'first_name',
          'last_name',
          'email.email',
          'address.street_address',
        ],
        'join' => [
          ['Email AS email', TRUE],
          [
            'Address AS address',
            'LEFT',
            NULL,
            ['address.is_primary', '=', TRUE],
          ],
        ],
        'where' => [
          ['id', '=', $id],
          ['email.is_primary', '=', TRUE],
          ['is_deleted', '=', FALSE],
        ],
        'checkPermissions' => FALSE,
      ]
    )->single();
  }

  public static function civiApi4CreateContact(
    string $firstName,
    string $lastName,
    string $emailAddress = NULL
  ): \Civi\Api4\Generic\Result {
    $apiCreateParams = [
      'values' => [
        'first_name' => $firstName,
        'last_name' => $lastName,
      ],
    ];
    if (!is_null($emailAddress)) {
      $apiCreateParams['chain'] = [
        'email' => [
          'Email',
          'create',
          [
            'values' => [
              'contact_id' => '$id',
              'email' => $emailAddress,
            ],
          ],
        ],
      ];
    }
    return civicrm_api4('Contact', 'create', $apiCreateParams);
  }

  public static function makeBlankOsdiPerson() {
    return new self::$personClass(self::$remoteSystem);
  }

  public static function setUpLocalAndRemotePeople_SameName_DifferentEmail($system) {
    $unsavedRemotePerson = self::makeNewOsdiPersonWithFirstLastEmail();
    $savedRemotePerson = $unsavedRemotePerson->save();
    $emailAddress = $savedRemotePerson->emailAddress->get();

    $differentEmailAddress = "fuzzityfizz.$emailAddress";
    $contactId = self::civiApi4CreateContact(
      $savedRemotePerson->givenName->get(),
      $savedRemotePerson->familyName->get(),
      $differentEmailAddress
    )->first()['id'];
    return [$contactId, $savedRemotePerson];
  }

  public static function makeNewOsdiPersonWithFirstLastEmail() {
    $unsavedNewPerson = self::makeBlankOsdiPerson();
    $unsavedNewPerson->givenName->set('Tester');
    $unsavedNewPerson->familyName->set('Von Test');
    $unsavedNewPerson->emailAddress->set('tester@testify.net');
    return $unsavedNewPerson;
  }

  public static function setUpExactlyOneMatchByEmailAndName(\Civi\Osdi\RemoteSystemInterface $system): array {
    $unsavedRemotePerson = self::makeNewOsdiPersonWithFirstLastEmail();
    $savedRemotePerson = $unsavedRemotePerson->save();

    $emailAddress = $savedRemotePerson->emailAddress->get();
    $firstName = $savedRemotePerson->givenName->get();
    $lastName = $savedRemotePerson->familyName->get();

    TestCase::assertNotEmpty($emailAddress);
    TestCase::assertNotEmpty($firstName);
    TestCase::assertNotEmpty($lastName);

    $idOfMatchingContact = self::civiApi4CreateContact(
      $firstName,
      $lastName,
      $emailAddress
    )->first()['id'];

    $localContactsWithTheEmailAndName = \Civi\Api4\Contact::get()
      ->addJoin('Email AS email', 'LEFT')
      ->addWhere('first_name', '=', $firstName)
      ->addWhere('last_name', '=', $lastName)
      ->addWhere('email.email', '=', $emailAddress)
      ->execute();

    TestCase::assertEquals(1, $localContactsWithTheEmailAndName->count());

    return [$savedRemotePerson, $idOfMatchingContact];
  }

  public static function setUpRemotePerson_TwoLocalContactsMatchingByEmail_OneAlsoMatchingByName($system): array {
    [
      $savedRemotePerson,
      $idOfMatchingContact,
    ] = self::setUpExactlyOneMatchByEmailAndName($system);

    $emailAddress = $savedRemotePerson->emailAddress->get();

    $idOf_Non_MatchingContact = self::civiApi4CreateContact(
      "{$savedRemotePerson->givenName->get()} with some extra",
      'foo',
      $emailAddress
    )->first()['id'];

    $civiContactsWithSameEmail = self::civiApi4GetContactByEmail($emailAddress);
    TestCase::assertGreaterThan(1, $civiContactsWithSameEmail->count());

    return [
      $savedRemotePerson,
      $idOfMatchingContact,
      $idOf_Non_MatchingContact,
    ];
  }

  /**
   * @return array [$emailAddress, $savedRemotePerson, $contactId]
   */
  public static function setUpExactlyOneMatchByEmail_DifferentNames(): array {
    $savedRemotePerson = self::makeNewOsdiPersonWithFirstLastEmail()->save();
    $emailAddress = $savedRemotePerson->emailAddress->get();
    TestCase::assertNotEmpty($emailAddress);
    TestCase::assertNotEquals('Fizz', $savedRemotePerson->givenName->get());
    $contactId = self::civiApi4CreateContact('Fizz', 'Bang', $emailAddress)
      ->first()['id'];
    $ContactsWithTheEmailAddress = self::civiApi4GetContactByEmail($emailAddress);
    TestCase::assertEquals(1, $ContactsWithTheEmailAddress->count());
    return [$emailAddress, $savedRemotePerson, $contactId];
  }

  public static function setUpRemotePerson_TwoLocalContactsMatchingByEmail_NeitherMatchingByName(): array {
    $unsavedRemotePerson = self::makeNewOsdiPersonWithFirstLastEmail();
    $savedRemotePerson = $unsavedRemotePerson->save();

    $emailAddress = $savedRemotePerson->emailAddress->get();
    TestCase::assertNotEmpty($emailAddress);

    $firstName = $savedRemotePerson->givenName->get();
    TestCase::assertNotEquals($firstName, 'foo');
    TestCase::assertNotEquals($firstName, 'bar');

    $idsOfContactsWithSameEmailAndDifferentName[] = self::civiApi4CreateContact(
      'foo',
      'foo',
      $emailAddress
    )->first()['id'];
    $idsOfContactsWithSameEmailAndDifferentName[] = self::civiApi4CreateContact(
      'bar',
      'bar',
      $emailAddress
    )->first()['id'];
    return [$savedRemotePerson, $idsOfContactsWithSameEmailAndDifferentName];
  }

  public static function setUpRemotePerson_TwoLocalContactsMatchingByEmail_BothMatchingByName(\Civi\Osdi\ActionNetwork\RemoteSystem $system): array {
    $unsavedRemotePerson = self::makeNewOsdiPersonWithFirstLastEmail();
    $savedRemotePerson = $unsavedRemotePerson->save();

    $emailAddress = $savedRemotePerson->emailAddress->get();
    TestCase::assertNotEmpty($emailAddress);

    $firstName = $savedRemotePerson->givenName->get();
    $lastName = $savedRemotePerson->familyName->get();

    $idsOfContactsWithSameEmailAndSameName[] = self::civiApi4CreateContact(
      $firstName,
      $lastName,
      $emailAddress
    )->first()['id'];
    $idsOfContactsWithSameEmailAndSameName[] = self::civiApi4CreateContact(
      $firstName,
      $lastName,
      $emailAddress
    )->first()['id'];
    return [$savedRemotePerson, $idsOfContactsWithSameEmailAndSameName];
  }

}
