<?php

namespace Civi\Osdi;

use Civi\Api4\Contact;
use Civi\Api4\OsdiPersonSyncState;
use OsdiClient\ActionNetwork\TestUtils;
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
    self::$system = TestUtils::createRemoteSystem();
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

}
