<?php

use CRM_OSDI_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Tests Create/Read/Update/Delete on remote OSDI system
 *
 * @group headless
 */
class CRM_OSDI_Generic_RemoteSystemCrudTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /** @var \Civi\Osdi\Mock\RemoteSystem $remoteSystem */
  public $remoteSystem;

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return \Civi\Test::headless()
      ->installMe(__DIR__)
        ->callback([$this, 'initializeRemoteSystem'], 'foo')
        ->apply();
  }


  public function setUp() {
    parent::setUp();
  }

  public function tearDown() {
    parent::tearDown();
  }

  public function createRemoteSystem(): \Civi\Osdi\Mock\RemoteSystem {
    return new Civi\Osdi\Mock\RemoteSystem();
  }

  public function initializeRemoteSystem() {
    //$this->remoteSystem = new CRM_OSDI_BAO_SyncProfile_Mock();
/*    $apiCreateSystemResult = \Civi\Api4\OsdiSyncProfile::create()
        ->addValue('label', 'Action Network Test')
        ->addValue('entry_point', 'https://actionnetwork.org/api/v2/')
        ->addValue('api_token', '')
    ->execute();*/
/*    $apiCreateSystemResult = \Civi\Api4\OsdiSyncProfile::create()
        ->addValue('label', 'Open Supporter Test')
        ->addValue('entry_point', 'http://api.opensupporter.org/api/v1')
        ->addValue('api_token', '')
        ->execute();
    $apiCreateSystemResult = \Civi\Api4\OsdiSyncProfile::create()
        ->addValue('label', 'Open Supporter Test')
        ->addValue('entry_point', 'http://lemniscus.demo.osdi.io/api/v1')
        ->addValue('api_token', '')
        ->execute();*/
    /*$remoteSystemId = $apiCreateSystemResult->column('id')[0];
    print "Remote System id: $remoteSystemId\n";
    $this->remoteSystem = CRM_OSDI_BAO_SyncProfile::findById($remoteSystemId);*/
    $this->remoteSystem = $this->createRemoteSystem();
  }

  public function makeBlankRemotePerson() {
    return new Civi\Osdi\RemotePerson();
  }

  public function makeExistingRemotePerson() {
    $remotePersonTest = new CRM_OSDI_RemotePersonTest();
    return $remotePersonTest->makeExistingRemotePerson();
  }

  public function expected($key) {
    $expected = [];
    if (array_key_exists($key, $expected)) return $expected[$key];
    $remotePersonTest = new CRM_OSDI_RemotePersonTest();
    return $remotePersonTest->expected($key);
  }

  public function testPersonCreateReadUpdateDelete() {
    $system = $this->createRemoteSystem();

    // CREATE
    $unsavedNewPerson = $this->makeBlankRemotePerson();
    $unsavedNewPerson->set('given_name', 'Testy');
    $unsavedNewPerson->set('family_name', 'McTest');
    $unsavedNewPerson->set('email_addresses', [['address' => 'testy@test.net']]);
    $this->assertNull($unsavedNewPerson->getId());
    $savedPerson = $system->save($unsavedNewPerson);
    $savedPersonId = $savedPerson->getId();
    $this->assertNotNull($savedPersonId);

    // READ
    /** @var \Civi\Osdi\Generic\OsdiPerson $fetchedRemotePerson */
    $fetchedRemotePerson = $system->fetchPersonById($savedPersonId);
    $this->assertEquals([['address' => 'testy@test.net']], $fetchedRemotePerson->getOriginal('email_addresses'));

    // UPDATE
    $fetchedRemotePerson->appendTo('email_addresses', ['address' => 'a.different.test@test.com']);
    $reSavedRemotePerson = $system->savePerson($fetchedRemotePerson);
    $this->assertEquals(
        [['address' => 'testy@test.net'],['address' => 'a.different.test@test.com']],
        $reSavedRemotePerson->getOriginal('email_addresses'));

    // DELETE
    $system->delete('person', $reSavedRemotePerson);
    try {
      $system->fetchPersonById($savedPersonId);
      $this->assertEquals('We should not reach this assert',
          "After trying to find a deleted person by id" );
    } catch (\Civi\Osdi\Exception\EmptyResultException $e) {
      $this->assertEquals('Deleted person lookup: empty result', 'Deleted person lookup: empty result' );
    }
  }

  public function testLookupPersonByEmail() {
    $system = $this->createRemoteSystem();
    $originalPerson = $this->makeExistingRemotePerson();
    $email = $originalPerson->getOriginalEmailAddress();
    $savedPerson = $system->savePerson($originalPerson);
    $savedPersonId = $savedPerson->getId();
    $searchResults = $system->find('person', [['email', '=', $email]]);
    $resultIds = array_map(function ($remotePerson) { return $remotePerson->getId(); }, $searchResults);
    $this->assertContains($savedPersonId, $resultIds);
  }


}
