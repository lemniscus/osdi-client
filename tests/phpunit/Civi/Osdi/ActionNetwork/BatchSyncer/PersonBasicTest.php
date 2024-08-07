<?php

namespace Civi\Osdi\ActionNetwork\BatchSyncer;

use Civi;
use Civi\Osdi\LocalObject\PersonBasic as LocalPerson;
use Civi\OsdiClient;
use OsdiClient\ActionNetwork\TestUtils;
use PHPUnit;

/**
 * Test \Civi\Osdi\RemoteSystemInterface
 *
 * @group headless
 */
class PersonBasicTest extends PHPUnit\Framework\TestCase implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\TransactionalInterface {

  public static \Civi\Osdi\ActionNetwork\RemoteSystem $system;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    self::$system = TestUtils::createRemoteSystem();
    parent::setUp();
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  public function testBatchSyncFromANDoesNotRunConcurrently() {
    $remotePerson = new \Civi\Osdi\ActionNetwork\Object\Person(self::$system);
    $remotePerson->emailAddress->set($email = "syncjobtest-no-concurrent@null.org");
    $remotePerson->save();

    $justBeforePersonWasModified =
      \Civi\Osdi\ActionNetwork\RemoteSystem::formatDateTime(
      strtotime($remotePerson->modifiedDate->get()) - 1);

    Civi::settings()->add([
      'osdiClient.syncJobProcessId' => getmypid(),
      'osdiClient.personBatchSyncActNetModTimeCutoff' => $justBeforePersonWasModified,
      'osdiClient.syncJobEndTime' => NULL,
    ]);

    $batchSyncer = OsdiClient::container()
      ->getSingle('BatchSyncer', 'Person');

    $batchSyncer->batchSyncFromRemote();
    $syncedContactCount = \Civi\Api4\Email::get(FALSE)
      ->addWhere('email', '=', $email)
      ->execute()->count();

    self::assertEquals(0, $syncedContactCount);

    Civi::settings()->set('osdiClient.syncJobProcessId', 9999999999999);
    sleep(1);
    $batchSyncer->batchSyncFromRemote();

    $syncedContactCount = \Civi\Api4\Email::get(FALSE)
      ->addWhere('email', '=', $email)
      ->execute()->count();

    self::assertEquals(1, $syncedContactCount);
  }

  public function testBatchSyncFromAN() {
    $localPeople = $this->setUpBatchSyncFromAN();

    $syncStartTime = time();

    $batchSyncer = OsdiClient::container()
      ->getSingle('BatchSyncer', 'Person');

    $batchSyncer->batchSyncFromRemote();

    $this->assertBatchSyncFromAN($localPeople, $syncStartTime);
  }

  public function testBatchSyncFromCivi() {
    [$remotePeople, $maxRemoteModTimeBeforeSync] = $this->setUpBatchSyncFromCivi();

    $syncStartTime = time();

    $batchSyncer = OsdiClient::container()->getSingle('BatchSyncer', 'Person');

    $batchSyncer->batchSyncFromLocal();

    $this->assertBatchSyncFromCivi($remotePeople, $syncStartTime, $maxRemoteModTimeBeforeSync);
  }

  public function testBatchSyncViaApiCall() {
    $localPeople = $this->setUpBatchSyncFromAN();
    [$remotePeople, $maxRemoteModTimeBeforeSync] = $this->setUpBatchSyncFromCivi();

    $syncStartTime = time();
    sleep(1);

    $syncProfileId = OsdiClient::container()->getSyncProfileId();
    $result = civicrm_api3('Job', 'osdiclientbatchsynccontacts',
      ['debug' => 1, 'origin' => 'remote,local', 'sync_profile_id' => $syncProfileId]);

    sleep(1);

    self::assertEquals(0, $result['is_error']);
    $this->assertBatchSyncFromAN($localPeople, $syncStartTime);
    $this->assertBatchSyncFromCivi($remotePeople, $syncStartTime, $maxRemoteModTimeBeforeSync);
  }

  /**
   * Create 4 remote people, 4 matching local people, and PersonSyncStates
   * linking them together. Make remote person #3 eligible for sync due to
   * changes since the last sync.
   *
   * @return \Civi\Osdi\LocalObject\PersonBasic[]
   */
  private function setUpBatchSyncFromAN(): array {
    $twoSecondsAgo = self::$system::formatDateTime(time() - 2);

    Civi::settings()->add([
      'osdiClient.syncJobProcessId' => 99999999999999,
      'osdiClient.personBatchSyncActNetModTimeCutoff' => $twoSecondsAgo,
      'osdiClient.syncJobStartTime' => '2000-11-11 00:00:00',
      'osdiClient.syncJobEndTime' => '2000-11-11 00:00:11',
    ]);

    $testTime = date('Y-m-d\TH:i:s\Z');

    for ($i = 1; $i < 5; $i++) {
      $email = "syncJobFromANTest$i@null.org";
      $lastName = "Sync Job Test $i $testTime";

      $remotePerson = new \Civi\Osdi\ActionNetwork\Object\Person(self::$system);
      $remotePerson->emailAddress->set($email);
      $remotePerson->givenName->set("ANtoCiviTest Remote person $i name");
      $remotePerson->familyName->set($lastName);

      /** @var \Civi\Osdi\ActionNetwork\Object\Person[] $remotePeople */
      $remotePeople[$i] = $remotePerson->save();
      $remoteModTime = $remotePerson->modifiedDate->get();
      $beforeRemoteModTime =
        date('Y-m-d H:i:s', strtotime($remoteModTime) - 10);
      Civi\Osdi\Logger::logDebug('[test]' . __FUNCTION__ . ' created AN id '
        . $remotePerson->getId() . ', mod ' . $remotePerson->modifiedDate->get()
        . ", $email");

      $localPerson = new LocalPerson();
      $localPerson->emailEmail->set($email);
      $localPerson->firstName->set("ANtoCiviTest Local person $i name");
      $localPerson->lastName->set($lastName);
      $localPeople[$i] = $localPerson->save();
      $localModTime = $localPerson->modifiedDate->get();

      // Create a PersonSyncState that links the RemotePerson to the LocalPerson

      $syncState = new \Civi\Osdi\PersonSyncState();
      $syncState->setSyncProfileId(OsdiClient::container()->getSyncProfileId());
      $syncState->setSyncOrigin(\Civi\Osdi\PersonSyncState::ORIGIN_REMOTE);
      $syncState->setRemotePersonId($remotePerson->getId());
      $syncState->setContactId($localPerson->getId());

      // Set it up to look like the RemotePerson was modified by the last sync
      // and has not been modified since then; likewise with the LocalPerson

      $syncState->setRemotePreSyncModifiedTime($beforeRemoteModTime);
      $syncState->setRemotePostSyncModifiedTime($remoteModTime);
      $syncState->setLocalPreSyncModifiedTime($beforeRemoteModTime);
      $syncState->setLocalPostSyncModifiedTime($localModTime);
      $syncState->save();
      /** @var \Civi\Osdi\PersonSyncState[] $syncStates */
      $syncStates[$i] = $syncState;
      Civi\Osdi\Logger::logDebug('[test] created PersonSyncState ' . print_r($syncState->toArray(), TRUE));

      // With .4 seconds between creations, person 4 will have a mod date at least
      // 1 second after person 1

      usleep(400000);
    }

    // Make sure person 3 has a mod time later than everyone else's

    usleep(700000);
    $remotePeople[3]->languageSpoken->set('es');
    $remotePeople[3]->save();

    self::assertGreaterThan(
      strtotime($syncStates[3]->getRemotePostSyncModifiedTime()),
      strtotime($remotePeople[3]->modifiedDate->get()),
      $syncStates[3]->getRemotePostSyncModifiedTime()
      . ' vs ' . $remotePeople[3]->modifiedDate->get(),
    );

    // "find" results can lag. we wait for them to catch up
    $foundByModDate = FALSE;
    for ($s = 0; $s < 5; $s++) {
      $searchResults = self::$system->find('osdi:people', [
        ['modified_date', 'gt', $testTime],
      ]);
      foreach ($searchResults as $remotePerson) {
        if ($remotePerson->getId() === $remotePeople[3]->getId()) {
          $foundByModDate = TRUE;
          break 2;
        }
      }
      sleep(1);
    }

    self::assertTrue($foundByModDate);

    return $localPeople;
  }

  /**
   * @param \Civi\Osdi\LocalObject\PersonBasic[] $localPeople
   * @param int $syncStartTime
   *
   * @return void
   */
  private function assertBatchSyncFromAN(array $localPeople, int $syncStartTime): void {
    self::assertGreaterThanOrEqual(
      $syncStartTime - 30,
      strtotime(\Civi::settings()->get('osdiClient.personBatchSyncActNetModTimeCutoff'))
    );

    foreach ($localPeople as $i => $localPerson) {
      $localPerson->load();
      if ($i === 3) {
        self::assertEquals("ANtoCiviTest Remote person $i name", $localPerson->firstName->get());
        self::assertGreaterThanOrEqual($syncStartTime, strtotime($localPerson->modifiedDate->get()));
        self::assertLessThan($syncStartTime + 60, strtotime($localPerson->modifiedDate->get()));
      }
      else {
        self::assertEquals("ANtoCiviTest Local person $i name", $localPerson->firstName->get());
        self::assertLessThan($syncStartTime, strtotime($localPerson->modifiedDate->get()));
      }
    }
  }

  private function setUpBatchSyncFromCivi(): array {
    $twoSecondsAgo = self::$system::formatDateTime(time() - 2);

    Civi::settings()->add([
      'osdiClient.syncJobProcessId' => 99999999999999,
      'osdiClient.syncJobCiviModTimeCutoff' => $twoSecondsAgo,
      'osdiClient.syncJobStartTime' => '2000-11-11 00:00:00',
      'osdiClient.syncJobEndTime' => '2000-11-11 00:00:11',
    ]);

    $testTime = time();
    $modTimeOfPreviousPerson = 0;

    /** @var \Civi\Osdi\LocalObject\PersonBasic[] $localPeople */
    for ($i = 1; $i < 5; $i++) {
      $email = "syncJobFromCiviTest$i@null.org";
      $lastName = "Sync Job Test $i $testTime";

      $localPerson = new LocalPerson();
      $localPerson->emailEmail->set($email);
      $localPerson->firstName->set("CiviToANTest Local person $i name");
      $localPerson->lastName->set($lastName);
      $localPeople[$i] = $localPerson->save();
      $localModTime = $localPerson->modifiedDate->get();
      $beforeLocalModTime = date('Y-m-d H:i:s',
        strtotime($localPerson->modifiedDate->get()) - 10);

      $remotePerson = new \Civi\Osdi\ActionNetwork\Object\Person(self::$system);
      $remotePerson->emailAddress->set($email);
      $remotePerson->givenName->set("CiviToANTest Remote person $i name");
      $remotePerson->familyName->set($lastName);
      $remotePeople[$i] = $remotePerson->save();
      $remoteModTime = $remotePerson->modifiedDate->get();
      $beforeRemoteModTime = date('Y-m-d H:i:s',
        strtotime($remotePerson->modifiedDate->get()) - 10);

      self::assertGreaterThanOrEqual($modTimeOfPreviousPerson, $remoteModTime);
      $modTimeOfPreviousPerson = $remoteModTime;

      // Create a PersonSyncState that links the RemotePerson to the LocalPerson

      $syncState = new \Civi\Osdi\PersonSyncState();
      $syncState->setSyncProfileId(OsdiClient::container()->getSyncProfileId());
      $syncState->setSyncOrigin(\Civi\Osdi\PersonSyncState::ORIGIN_REMOTE);
      $syncState->setRemotePersonId($remotePerson->getId());
      $syncState->setContactId($localPerson->getId());

      // Set it up to look like the RemotePerson was modified by the last sync
      // and has not been modified since then; likewise with the LocalPerson

      $syncState->setLocalPreSyncModifiedTime($beforeLocalModTime);
      $syncState->setLocalPostSyncModifiedTime($localModTime);
      $syncState->setRemotePreSyncModifiedTime($beforeRemoteModTime);
      $syncState->setRemotePostSyncModifiedTime($remoteModTime);
      $syncState->save();

      // With .4 seconds between creations, person 4 will have a mod date at least
      // 1 second after person 1

      usleep(400000);
    }

    // Make sure person 3 has a mod time later than everyone else's

    usleep(700000);
    $localPeople[3]->preferredLanguage->set('es');
    $localPeople[3]->save();

    return array($remotePeople, strtotime($remotePeople[4]->modifiedDate->get()));
  }

  private function assertBatchSyncFromCivi($remotePeople, int $syncStartTime, int $maxRemoteModTimeBeforeSync): void {
    foreach ($remotePeople as $i => $remotePerson) {
      /** @var \Civi\Osdi\ActionNetwork\Object\Person $remotePerson */
      $remotePerson->load();
      if ($i == 3) {
        self::assertEquals('CiviToANTest Local person 3 name', $remotePerson->givenName->get());
        self::assertGreaterThanOrEqual($syncStartTime, strtotime($remotePerson->modifiedDate->get()));
        self::assertLessThan($syncStartTime + 60, strtotime($remotePerson->modifiedDate->get()));
      }
      else {
        self::assertEquals("CiviToANTest Remote person $i name", $remotePerson->givenName->get());
        self::assertLessThanOrEqual($maxRemoteModTimeBeforeSync, strtotime($remotePerson->modifiedDate->get()));
      }
    }
  }

}
