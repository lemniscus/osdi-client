<?php

use PHPUnit\Framework\TestCase;

class CRM_OSDI_Fixture_PersonMatching {

  /**
   * @var string
   */
  public static $personClass;

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
        ],
        'join' => [
          ['Email AS email', TRUE],
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

  public static function makeBlankOsdiPerson(): \Civi\Osdi\RemoteObjectInterface {
    return new self::$personClass();
  }

  public static function setUpLocalAndRemotePeople_SameName_DifferentEmail($system) {
    $unsavedRemotePerson = self::makeNewOsdiPersonWithFirstLastEmail();
    $savedRemotePerson = $system->savePerson($unsavedRemotePerson);
    $emailAddress = $savedRemotePerson->getEmailAddress();

    $differentEmailAddress = "fuzzityfizz.$emailAddress";
    $contactId = self::civiApi4CreateContact(
      $savedRemotePerson->get('given_name'),
      $savedRemotePerson->get('family_name'),
      $differentEmailAddress
    )->first()['id'];
    return [$contactId, $savedRemotePerson];
  }


  public static function makeNewOsdiPersonWithFirstLastEmail(): \Civi\Osdi\RemoteObjectInterface {
    $unsavedNewPerson = self::makeBlankOsdiPerson();
    $unsavedNewPerson->set('given_name', 'Testy');
    $unsavedNewPerson->set('family_name', 'McTest');
    $unsavedNewPerson->set('email_addresses', [['address' => 'testy@test.net']]);
    return $unsavedNewPerson;
  }

  public static function setUpRemotePerson_TwoLocalContactsMatchingByEmail_OneAlsoMatchingByName($system): array {
    $unsavedRemotePerson = self::makeNewOsdiPersonWithFirstLastEmail();
    $savedRemotePerson = $system->savePerson($unsavedRemotePerson);

    $emailAddress = $savedRemotePerson->getEmailAddress();
    TestCase::assertNotEmpty($emailAddress);

    $firstName = $savedRemotePerson->get('given_name');
    $lastName = $savedRemotePerson->get('family_name');
    TestCase::assertNotEquals($firstName, 'foo');

    $idOfMatchingContact = self::civiApi4CreateContact(
      $firstName,
      $lastName,
      $emailAddress
    )->first()['id'];
    $idOf_Non_MatchingContact = self::civiApi4CreateContact(
      'foo',
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
  public static function setUpExactlyOneMatchByEmail_DifferentNames($system): array {
    $unsavedRemotePerson = self::makeNewOsdiPersonWithFirstLastEmail();
    $savedRemotePerson = $system->savePerson($unsavedRemotePerson);
    $emailAddress = $savedRemotePerson->getEmailAddress();
    TestCase::assertNotEmpty($emailAddress);
    $contactId = self::civiApi4CreateContact('Fizz', 'Bang', $emailAddress)
      ->first()['id'];
    $ContactsWithTheEmailAddress = self::civiApi4GetContactByEmail($emailAddress);
    TestCase::assertEquals(1, $ContactsWithTheEmailAddress->count());
    return [$emailAddress, $savedRemotePerson, $contactId];
  }

  public static function setUpRemotePerson_TwoLocalContactsMatchingByEmail_NeitherMatchingByName($system): array {
    $unsavedRemotePerson = self::makeNewOsdiPersonWithFirstLastEmail();
    $savedRemotePerson = $system->savePerson($unsavedRemotePerson);

    $emailAddress = $savedRemotePerson->getEmailAddress();
    TestCase::assertNotEmpty($emailAddress);

    $firstName = $savedRemotePerson->get('given_name');
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

}