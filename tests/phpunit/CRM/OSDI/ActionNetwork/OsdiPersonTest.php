<?php

use CRM_Osdi_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use Jsor\HalClient\HalClient;
use Jsor\HalClient\HalResource;

/**
 * Unit tests for ActionNetwork OsdiPerson class
 *
 * @group headless
 */
class CRM_OSDI_ActionNetwork_OsdiPersonTest extends CRM_OSDI_Generic_OsdiPersonTest implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function makeSystem() {
    return new Civi\Osdi\ActionNetwork\RemoteSystem(new CRM_OSDI_BAO_SyncProfile(), new HalClient(''));
  }

  public function makeBlankOsdiPerson() {
    return new Civi\Osdi\ActionNetwork\OsdiPerson();
  }

  public function makeExistingOsdiPerson() {
    $client = new HalClient('');
    $personResource = new HalResource($client, [
        'given_name' => 'Testy',
        'family_name' => 'McTest',
        'email_addresses' => [
            [
                "primary" => true,
                "address" => "testy@test.net",
                "status" => "subscribed",
            ],
        ],
        'phone_numbers' => [
            [
                'primary' => 'true',
                'number' => '12024444444',
                'number_type' => 'Mobile',
                'status' => 'subscribed',
            ],
        ],
        'identifiers' => ['action_network:d91b4b2e-ae0e-4cd3-9ed7-d0ec501b0bc3']
    ]);
    return new Civi\Osdi\ActionNetwork\OsdiPerson($personResource);
  }

  public function expected($key) {
    $expected = [
        'existingPersonUrl' => 'https://actionnetwork.org/api/v2/people/d91b4b2e-ae0e-4cd3-9ed7-d0ec501b0bc3',
        'existingPersonId' => 'd91b4b2e-ae0e-4cd3-9ed7-d0ec501b0bc3',
    ];
    return $expected[$key];
  }

  public function testOverwriteMultiValueField() {
    $this->markTestSkipped('In Action Network, multivalue fields cannot be overwritten');
  }

  public function testClearMultiValueField() {
    $this->markTestSkipped('In Action Network, multivalue fields cannot be cleared');
  }

  public function testClearWrongFieldThrowsException() {
    $person = $this->makeExistingOsdiPerson();
    foreach (['identifiers', 'email_addresses', 'phone_numbers', 'postal_addresses', 'languages_spoken'] as $fieldName){
        try {
            $person->clearField($fieldName);
            $this->fail('Exception should be thrown here');
        } catch (\Civi\Osdi\Exception\InvalidArgumentException $e) {
            $this->assertEquals(sprintf('Cannot clear field "%s"', $fieldName), $e->getMessage());
        }
    }
  }

}
