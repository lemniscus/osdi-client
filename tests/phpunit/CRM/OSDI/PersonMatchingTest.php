<?php

use CRM_OSDI_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 *
 *
 * @group headless
 */
class CRM_OSDI_PersonMatchingTest extends \PHPUnit\Framework\TestCase implements
    HeadlessInterface,
    HookInterface,
    TransactionalInterface {

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    $this->setUpExistingMatch();
    parent::setUp();
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  public function setUpExistingMatch() {
    $contactId = \Civi\Api4\Contact::create()
      ->addValue('contact_type', 'Individual')
      ->addValue(
        'first_name',
        'Shangela'
      )
      ->addValue('last_name', 'Wadley')
      ->addChain(
        'contactEmail',
        \Civi\Api4\Email::create()
          ->addValue('contact_id', '$id')
          ->addValue('email', 'shangela@fish.net')
      )
      ->execute()
      ->first()['id'];

    $remoteSystemId = \Civi\Api4\OsdiSyncProfile::create()
      ->addValue('label', 'Test')
      ->execute()
      ->first()['id'];

    \Civi\Api4\OsdiMatch::create()
      ->addValue('contact_id', $contactId)
      ->addValue(
        'remote_person_id',
        'shangelaIdOnRemoteSystem'
      )
      ->addValue('remote_system_id', $remoteSystemId)
      ->execute();
  }

  public function testForOsdiMatchDataStructure() {
    $fieldNames = \Civi\Api4\OsdiMatch::getFields()->execute()->column('name');
    $this->assertContains('contact_id', $fieldNames);
    $this->assertContains('remote_person_id', $fieldNames);
    $this->assertContains('sync_profile_id', $fieldNames);
    $this->assertContains('sync_origin_modified_time', $fieldNames);
    $this->assertContains('sync_target_modified_time', $fieldNames);
    $this->assertContains('sync_origin', $fieldNames);
  }

  public function testCheckForExistingLinkFromRemotePersonToCiviContact() {
    $apiMatchResults1 = \Civi\Api4\OsdiMatch::get()->setWhere(
      [['remote_person_id', '=', 'shangelaIdOnRemoteSystem']]
    )->execute();
    $this->assertEquals(1, $apiMatchResults1->count());

    $apiMatchResults2 = \Civi\Api4\OsdiMatch::get()->setWhere(
      [['remote_person_id', '=', 'nonexistentIdOnRemoteSystem']]
    )->execute();
    $this->assertEquals(0, $apiMatchResults2->count());
  }

}
