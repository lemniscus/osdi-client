<?php

namespace Civi\Osdi\ActionNetwork\BatchSyncer;

use Civi\Osdi\Factory;
use Civi\Osdi\Logger;
use Civi\Osdi\ActionNetwork\DonationHelperTrait;
use Civi\Osdi\ActionNetwork\Object\Donation as RemoteDonation;
use Civi\Osdi\ActionNetwork\Matcher\Donation\Basic as DonationBasicMatcher;
use Civi\Osdi\ActionNetwork\Mapper\DonationBasic as DonationBasicMapper;

use PHPUnit;

/**
 * Test \Civi\Osdi\RemoteSystemInterface
 *
 * @group headless
 *
 */
class DonationBasicTest extends PHPUnit\Framework\TestCase implements
  \Civi\Test\HeadlessInterface,
  \Civi\Test\TransactionalInterface {

  use DonationHelperTrait;

  /**
   * @var \Civi\Osdi\ActionNetwork\Mapper\Reconciliation2022May001
   */
  public static $mapper;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * Here we test that
   *
   * 1. two new donations at AN are synced to Civi.
   * 2. add a third donation and re-run the sync; we expect the third donation to come in, and not duplicates.
   *
   */
  public function testBatchSyncFromAN() {
    // Create fixture: 2 donations.
    // Don't run the test twice in one second, or this won't work ;-)
    $sets = [
      ['amount' => '1.23', 'when' => date('Y-m-d\TH:i:s\Z') ],
      ['amount' => '3.45', 'when' => date('Y-m-d\TH:i:s\Z', strtotime('now - 1 day')) ],
      ['amount' => '7.89', 'when' => date('Y-m-d\TH:i:s\Z', strtotime('now - 1 month')) ],
    ];
    $createdRemoteDonationIds = [
      $this->createRemoteDonationAndGetId($sets[0]),
      $this->createRemoteDonationAndGetId($sets[1]),
    ];
    $this->assertCount(2, $createdRemoteDonationIds, 'Failed creating remote fixture');

    // Call system under test.
    /** @var \Civi\Osdi\ActionNetwork\BatchSyncer\DonationBasic */
    $batchSyncer = $this->getBatchSyncer();
    $batchSyncer->batchSyncFromRemote();

    // We should now have the donation we created above. (We may have a load of others,
    // too, if you have run this test a few times within a week).
    $syncState = \Civi\Api4\OsdiDonationSyncState::get(FALSE)
    ->addSelect('remote_donation_id', 'contribution_id', 'contribution.total_amount', 'contribution.receive_date')
    ->addJoin('Contribution AS contribution', 'INNER', ['contribution_id', '=', 'contribution.id'])
    ->addWhere('remote_donation_id', 'IN', $createdRemoteDonationIds)
    ->execute()
    ->indexBy('remote_donation_id')
    ->getArrayCopy();
    foreach ($createdRemoteDonationIds as $i => $remoteId) {
      $this->assertArrayHasKey($remoteId, $syncState, "Missing remote id in dataset $i: $remoteId");
      $this->assertEquals($sets[$i]['amount'], $syncState[$remoteId]['contribution.total_amount'], "Mismatch of amount on dataset $i");
      $this->assertEquals(strtr($sets[$i]['when'], ['Z' => '', 'T'=> ' ']), $syncState[$remoteId]['contribution.receive_date'], "Mismatch of receive date on dataset $i");
    }
    $countOfDonations = \Civi\Api4\Contribution::get(FALSE)->selectRowCount()->addWhere('contact_id', '=', static::$createdEntities['Contact'][0])->addWhere('is_test', 'IN', [0, 1])->execute()->count();
    $this->assertEquals(2, $countOfDonations, "Expected 2 contributions, but got $countOfDonations");

    // Part 2: create third donation.
    $createdRemoteDonationIds[] = $this->createRemoteDonationAndGetId($sets[2]);
    $batchSyncer->batchSyncFromRemote();

    $newCountOfDonations = \Civi\Api4\Contribution::get(FALSE)->selectRowCount()->addWhere('contact_id', '=', static::$createdEntities['Contact'][0])->addWhere('is_test', 'IN', [0, 1])->execute()->count();
    $this->assertEquals(3, $newCountOfDonations, "Expected 3 donations now but got $newCountOfDonations");

    $syncState2 = \Civi\Api4\OsdiDonationSyncState::get(FALSE)
    ->addSelect('remote_donation_id', 'contribution_id', 'contribution.total_amount', 'contribution.receive_date')
    ->addJoin('Contribution AS contribution', 'INNER', ['contribution_id', '=', 'contribution.id'])
    ->addWhere('remote_donation_id', 'IN', $createdRemoteDonationIds)
    ->execute()
    ->indexBy('remote_donation_id')
    ->getArrayCopy();

    foreach ($createdRemoteDonationIds as $i => $remoteId) {
      if ($i < 2) {
        // First two donations' should not have changed
        $this->assertArrayHasKey($remoteId, $syncState2, "Missing remote id in dataset $i: $remoteId in OsdiDonationSyncState results fetched after repeat sync call");
        $this->assertEquals($syncState[$remoteId], $syncState2[$remoteId], "Sync state has changed after repeat call");
      }
      else {
        // Third donation is new, should match $set[2]
        $this->assertArrayHasKey($remoteId, $syncState2, "Missing remote id in dataset $i: $remoteId in OsdiDonationSyncState results fetched after repeat sync call");
        $this->assertEquals($sets[$i]['amount'], $syncState2[$remoteId]['contribution.total_amount'], "Mismatch of amount on dataset $i");
        $this->assertEquals(strtr($sets[$i]['when'], ['Z' => '', 'T'=> ' ']), $syncState2[$remoteId]['contribution.receive_date'], "Mismatch of receive date on dataset $i");
      }
    }

  }

  protected function createRemoteDonationAndGetId(array $set): string {
      $remoteDonationToday = new RemoteDonation(static::$system);
      $recipients = [['display_name' => 'Test recipient financial type', 'amount' => $set['amount']]];
      $remoteDonationToday->recipients->set($recipients);
      $remoteDonationToday->createdDate->set($set['when']);
      $remoteDonationToday->setDonor(self::$testRemotePerson);
      $remoteDonationToday->setFundraisingPage(self::$testFundraisingPage);
      $remoteDonationToday->recurrence->set(['recurring' => FALSE]);
      $remoteDonationToday->save();
      return $remoteDonationToday->getId();
  }

  public function testBatchSyncFromCivi() {
    // NM todo: make it test syncing at least 2 donations
    // Create a donation in Civi, call sync, load recent donations and check it's there.
    $now = time();

    // Create fixture.
    // NM todo: add something more unique that we can check for
    $contribution = civicrm_api3('Order', 'create', [
      'financial_type_id' => 1,
      'contact_id' => static::$createdEntities['Contact'][0],
      'total_amount' => 1.23,
    ]);
    civicrm_api3('Payment', 'create', [
      'contribution_id' => $contribution['id'],
      'total_amount' => 1.23,
      'trxn_id' => 'abc',
    ]);

    // Call system under test
    $batchSyncer = $this->getBatchSyncer();
    $batchSyncer->batchSyncFromLocal();

    // Check expectations
    // There should be a remote donation for this.
    $donations = static::$system->find('osdi:donations', [
      ['modified_date', 'gt', date('Y-m-d\TH:i:s\Z', $now - 60)],
    ]);
    // ? how to check if it's the right person?
    $found = NULL;
    foreach ($donations as $donation) {
      /** @var RemoteDonation $donation */
      if ($donation->amount->get() == 1.23) {
        // Assume it's this one.
        $found = $donation;
      }
    }
    $this->assertNotNull($found);
  }

  protected function getBatchSyncer(): \Civi\Osdi\ActionNetwork\BatchSyncer\DonationBasic {
    // Call system under test.
    /** @var \Civi\Osdi\ActionNetwork\SingleSyncer\Donation\DonationBasic */
    $singleSyncer = Factory::singleton('SingleSyncer', 'Donation', self::$system);
    $matcher = new DonationBasicMatcher();
    $singleSyncer->setMatcher($matcher);
    $mapper = new DonationBasicMapper(static::$system, static::$personMatcher);
    $singleSyncer->setMapper($mapper);
    /** @var \Civi\Osdi\ActionNetwork\BatchSyncer\DonationBasic **/
    $batchSyncer = Factory::singleton('BatchSyncer', 'Donation', $singleSyncer);

    return $batchSyncer;
  }

}

