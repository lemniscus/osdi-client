<?php

namespace Civi\Osdi;

use Civi\Api4\Contact;
use Civi\Api4\OsdiPersonSyncState;
use CRM_OSDI_ActionNetwork_TestUtils;
use PHPUnit;

/**
 * @group headless
 */
class PersonSyncStateTest extends PHPUnit\Framework\TestCase implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\TransactionalInterface {

  private static ActionNetwork\RemoteSystem $system;

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  protected function setUp(): void {
    self::$system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
    parent::setUp();
  }

  public function testIsDeletedWhenContactIsHardDeleted() {
    $localPerson = new LocalObject\PersonBasic();
    $localPerson->firstName->set('Euphoria');
    $localPerson->emailEmail->set('e@p.us');
    $localPerson->save();
    $contactId = $localPerson->getId();

    self::assertNotNull($contactId);

    $syncState = new PersonSyncState();
    $syncState->setContactId($contactId);
    $syncState->setRemotePersonId('foo');
    $syncState->save();
    $syncStateId = $syncState->getId();

    self::assertNotNull($syncStateId);

    $syncStateGetResult = OsdiPersonSyncState::get(FALSE)
      ->addWhere('id', '=', $syncStateId)
      ->execute();

    self::assertCount(1, $syncStateGetResult);

    Contact::delete(FALSE)
      ->setUseTrash(FALSE)
      ->addWhere('id', '=', $contactId)
      ->execute();

    $syncStateGetResult = OsdiPersonSyncState::get(FALSE)
      ->addWhere('id', '=', $syncStateId)
      ->execute();

    self::assertCount(0, $syncStateGetResult);
  }

  public function testIsNOTDeletedWhenContactIsSoftDeleted() {
    $localPerson = new LocalObject\PersonBasic();
    $localPerson->firstName->set('Euphoria');
    $localPerson->emailEmail->set('e@p.us');
    $localPerson->save();
    $contactId = $localPerson->getId();

    self::assertNotNull($contactId);

    $syncState = new PersonSyncState();
    $syncState->setContactId($contactId);
    $syncState->setRemotePersonId('foo');
    $syncState->save();
    $syncStateId = $syncState->getId();

    self::assertNotNull($syncStateId);

    $syncStateGetResult = OsdiPersonSyncState::get(FALSE)
      ->addWhere('id', '=', $syncStateId)
      ->execute();

    self::assertCount(1, $syncStateGetResult);

    Contact::delete(FALSE)
      ->setUseTrash(TRUE)
      ->addWhere('id', '=', $contactId)
      ->execute();

    $syncStateGetResult = OsdiPersonSyncState::get(FALSE)
      ->addWhere('id', '=', $syncStateId)
      ->execute();

    self::assertCount(1, $syncStateGetResult);
  }

  public function testSyncStatesAreDeletedInMerge() {
    $localPerson1 = new LocalObject\PersonBasic();
    $localPerson1->firstName->set('Euphoria');
    $localPerson1->emailEmail->set('e@p.us');
    $localPerson1->save();
    $contactId1 = $localPerson1->getId();

    $localPerson2 = new LocalObject\PersonBasic();
    $localPerson2->firstName->set('EUPHORIA');
    $localPerson2->emailEmail->set('e@p.us');
    $localPerson2->save();
    $contactId2 = $localPerson2->getId();

    $syncState1 = new PersonSyncState();
    $syncState1->setContactId($contactId1);
    $syncState1->setRemotePersonId('foofoofoo');
    $syncState1->save();
    $syncStateId1 = $syncState1->getId();

    $syncState2 = new PersonSyncState();
    $syncState2->setContactId($contactId2);
    $syncState2->setRemotePersonId('barbarbar');
    $syncState2->save();
    $syncStateId2 = $syncState2->getId();

    $syncStateGetResult = OsdiPersonSyncState::get(FALSE)
      ->addWhere('contact_id', 'IN', [$contactId1, $contactId2])
      ->execute();

    self::assertCount(2, $syncStateGetResult);

    $result = civicrm_api3('Contact', 'merge', [
      'to_remove_id' => $contactId2,
      'to_keep_id' => $contactId1,
      'mode' => "aggressive",
    ]);

    self::assertEmpty($result['is_error']);

    $syncStateGetResult = OsdiPersonSyncState::get(FALSE)
      ->addWhere('contact_id', 'IN', [$contactId1, $contactId2])
      ->execute();

    self::assertCount(0, $syncStateGetResult,
      'Sync States should be deleted by merge hook');
  }

}
