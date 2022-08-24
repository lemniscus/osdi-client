<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class CRM_OSDI_LocalObject_PersonTest extends \PHPUnit\Framework\TestCase implements
    HeadlessInterface,
    TransactionalInterface {

  public \Civi\Osdi\LocalObject\Person $person;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    $this->person = new \Civi\Osdi\LocalObject\Person();
    parent::setUp();
  }

  public function tearDown(): void {
    $this->person->delete();
    parent::tearDown();
  }

  private function createCookieCutterContact(): array {
    $contact = Civi\Api4\Contact::create()->setValues(
      [
        'first_name' => 'Cookie',
        'last_name' => 'Cutter',
        'preferred_language:name' => 'es_MX',
      ]
    )->addChain('email', \Civi\Api4\Email::create()
      ->setValues(
        [
          'contact_id' => '$id',
          'email' => 'cookie@yum.net',
        ]
      )
    )->addChain('phone', \Civi\Api4\Phone::create()
      ->setValues(
        [
          'contact_id' => '$id',
          'phone' => '12023334444',
          'phone_type_id:name' => 'Mobile',
        ]
      )
    )->addChain('address', \Civi\Api4\Address::create()
      ->setValues(
        [
          'contact_id' => '$id',
          'street_address' => '123 Test St',
          'city' => 'Licking',
          'state_province_id:name' => 'Missouri',
          'postal_code' => 65542,
          'country_id:name' => 'US',
        ]
      )
    )->execute()->single();
    return $contact;
  }

  public function testSetIdAndGetId() {
    $this->person->setId(123);
    self::assertEquals(123, $this->person->getId());
  }

  public function testClone() {
    $original = $this->person;
    $original->firstName->set('A');
    $clone1 = clone $original;

    self::assertFalse($original->isLoaded());
    self::assertFalse($clone1->isLoaded());

    self::assertTrue($original->isAltered());
    self::assertTrue($clone1->isAltered());

    self::assertEquals($original->firstName->get(), $clone1->firstName->get());

    $clone1->firstName->set('B');

    self::assertNotEquals($original->firstName->get(), $clone1->firstName->get());

    $original->save();

    self::assertTrue($original->isLoaded());
    self::assertFalse($clone1->isLoaded());

    $clone2 = clone $original;

    self::assertFalse($original->isTouched());
    self::assertFalse($clone2->isTouched());

    $clone2->lastName->set('C');

    self::assertFalse($original->isTouched());
    self::assertTrue($clone2->isTouched());
  }

  public function testDelete() {
    $this->person->setId(\Civi\Api4\Contact::create(FALSE)
      ->addValue('first_name', 'Willow')
      ->execute()->single()['id']);

    self::assertEquals(
      'Willow',
      \Civi\Api4\Contact::get(FALSE)
        ->addWhere('id', '=', $this->person->getId())
        ->execute()->single()['first_name']
    );

    $this->person->delete();

    self::assertCount(0, \Civi\Api4\Contact::get(FALSE)
      ->addWhere('id', '=', $this->person->getId())
      ->addWhere('is_deleted', '=', FALSE)
      ->execute());
  }

  public function testSetAndGetSuccess() {
    $this->person->lastName->set('Pill');
    self::assertEquals('Pill', $this->person->lastName->get());
  }

  public function testSetReadOnlyFieldThrowsException() {
    self::expectException(\Civi\Osdi\Exception\InvalidOperationException::class);
    $this->person->createdDate->set('foo');
  }

  public function testLoad() {
    self::assertFalse($this->person->isLoaded());
    $id = \Civi\Api4\Contact::create(FALSE)
      ->addValue('first_name', 'Kornbread')
      ->execute()->single()['id'];
    \Civi\Api4\Address::create(FALSE)
      ->addValue('contact_id', $id)
      ->addValue('state_province_id:name', 'Utah')
      ->execute();
    $this->person->setId($id);
    $this->person->load();
    self::assertEquals('Kornbread', $this->person->firstName->get());
    self::assertEquals('UT',
      $this->person->addressStateProvinceIdAbbreviation->get());
    self::assertTrue($this->person->isLoaded());
  }

  public function testIsTouched() {
    $id = \Civi\Api4\Contact::create(FALSE)
      ->addValue('first_name', 'Robyn')
      ->execute()->single()['id'];
    $person = Civi\Osdi\LocalObject\Person::fromId($id);
    self::assertTrue($person->isLoaded());
    self::assertFalse($person->isTouched());
    $person->firstName->set('Robyn');
    self::assertTrue($person->isTouched());
  }

  public function testIsAltered() {
    $id = \Civi\Api4\Contact::create(FALSE)
      ->addValue('first_name', 'Solange')
      ->execute()->single()['id'];
    $person = \Civi\Osdi\LocalObject\Person::fromId($id);
    self::assertFalse($person->isAltered());
    $person->firstName->set('Beyonce');
    self::assertTrue($person->isAltered());
    $person->firstName->set('Solange');
    self::assertFalse($person->isAltered());
  }

  public function testGetAllLoaded() {
    $contact = $this->createCookieCutterContact();
    $person = Civi\Osdi\LocalObject\Person::fromId($contact['id']);
    $expected = [
      'id' => (string) $contact['id'],
      'createdDate' => $contact['created_date'],
      'modifiedDate' => \Civi\Api4\Contact::get(FALSE)
        ->addWhere('id', '=', $contact['id'])
        ->execute()->single()['modified_date'],
      'firstName' => 'Cookie',
      'lastName' => 'Cutter',
      'isOptOut' => '',
      'doNotEmail' => '',
      'doNotSms' => '',
      'preferredLanguage' => 'es_MX',
      'preferredLanguageName' => 'es_MX',
      'isDeleted' => '',
      'emailEmail' => 'cookie@yum.net',
      'phonePhone' => '12023334444',
      'phonePhoneNumeric' => '12023334444',
      'addressStreetAddress' => '123 Test St',
      'addressCity' => 'Licking',
      'addressStateProvinceId' => '1024',
      'addressStateProvinceIdAbbreviation' => 'MO',
      'addressPostalCode' => '65542',
      'addressCountryId' => '1228',
      'addressCountryIdName' => 'US',
    ];

    $actual = $person->getAllLoaded();
    unset($actual['emailId'], $actual['phoneId'], $actual['addressId']);
    self::assertEquals($expected, $actual);
  }

  public function testGetAll() {
    $contact = $this->createCookieCutterContact();
    $person = \Civi\Osdi\LocalObject\Person::fromId($contact['id']);
    $person->lastName->set('MONSTER');
    $person->addressStateProvinceId->set(1001);

    $expected = [
      'id' => (string) $contact['id'],
      'createdDate' => $contact['created_date'],
      'modifiedDate' => \Civi\Api4\Contact::get(FALSE)
        ->addWhere('id', '=', $contact['id'])
        ->execute()->single()['modified_date'],
      'firstName' => 'Cookie',
      'lastName' => 'MONSTER',
      'isOptOut' => FALSE,
      'doNotEmail' => FALSE,
      'doNotSms' => FALSE,
      'preferredLanguage' => 'es_MX',
      'preferredLanguageName' => 'es_MX',
      'isDeleted' => FALSE,
      'emailEmail' => 'cookie@yum.net',
      'phonePhone' => '12023334444',
      'phonePhoneNumeric' => '12023334444',
      'addressStreetAddress' => '123 Test St',
      'addressCity' => 'Licking',
      'addressStateProvinceId' => 1001,
      'addressStateProvinceIdAbbreviation' => 'AK',
      'addressPostalCode' => '65542',
      'addressCountryId' => 1228,
      'addressCountryIdName' => 'US',
    ];
    $actual = $person->getAll();
    unset($actual['emailId'], $actual['phoneId'], $actual['addressId']);
    self::assertEquals($expected, $actual);
  }

  public function testSaveNewAndLoad() {
    $p1 = new \Civi\Osdi\LocalObject\Person();
    $p1->firstName->set('Cookie');
    $p1->doNotEmail->set(TRUE);
    $p1->emailEmail->set('cookie@yum.net');
    $p1->phonePhone->set('12023334444');
    $p1->addressStreetAddress->set('123 Test St');
    $p1->save();

    self::assertNotNull($p1->getId());

    $p2 = Civi\Osdi\LocalObject\Person::fromId($p1->getid());
    $p2->load();

    self::assertEquals($p1->firstName->get(), $p2->firstName->get());
    self::assertEquals($p1->doNotEmail->get(), $p2->doNotEmail->get());
    self::assertEquals($p1->emailEmail->get(), $p2->emailEmail->get());
    self::assertEquals($p1->phonePhone->get(), $p2->phonePhone->get());
    self::assertEquals($p1->addressStreetAddress->get(), $p2->addressStreetAddress->get());
  }

  public function testSaveDoesNotCreateExtraCopiesOfEmailEtc() {
    $p1 = new \Civi\Osdi\LocalObject\Person();
    $p1->firstName->set('Cookie');
    $p1->emailEmail->set('cookie@yum.net');
    $p1->phonePhone->set('12023334444');
    $p1->addressStreetAddress->set('123 Test St');
    $p1->save();
    $cid = $p1->getId();

    self::assertEquals(1, \Civi\Api4\Email::get(FALSE)
      ->addWhere('contact_id', '=', $cid)
      ->selectRowCount()->execute()->count());
    self::assertEquals(1, \Civi\Api4\Phone::get(FALSE)
      ->addWhere('contact_id', '=', $cid)
      ->selectRowCount()->execute()->count());
    self::assertEquals(1, \Civi\Api4\Address::get(FALSE)
      ->addWhere('contact_id', '=', $cid)
      ->selectRowCount()->execute()->count());

    $p1again = Civi\Osdi\LocalObject\Person::fromId($cid);
    $p1again->emailEmail->set('cookie@yum.net');
    $p1again->phonePhone->set('12023334444');
    $p1again->addressStreetAddress->set('123 Test St');
    $p1again->save();

    self::assertEquals(1, \Civi\Api4\Email::get(FALSE)
      ->addWhere('contact_id', '=', $cid)
      ->selectRowCount()->execute()->count());
    self::assertEquals(1, \Civi\Api4\Phone::get(FALSE)
      ->addWhere('contact_id', '=', $cid)
      ->selectRowCount()->execute()->count());
    self::assertEquals(1, \Civi\Api4\Address::get(FALSE)
      ->addWhere('contact_id', '=', $cid)
      ->selectRowCount()->execute()->count());
  }

  public function testSaveWithEmptyEmailOrPhone() {
    $this->person->firstName->set('Cookie');
    $this->person->emailEmail->set(NULL);
    $this->person->phonePhone->set(NULL);
    $this->person->save();
    $this->person->load();

    self::assertNull($this->person->emailEmail->get());
  }

}
