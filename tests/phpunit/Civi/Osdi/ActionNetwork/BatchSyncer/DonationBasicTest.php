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

  public function testBatchSyncFromAN() {
    // $syncState = \Civi\Api4\OsdiDonationSyncState::get(FALSE)
    // ->addSelect('remote_donation_id', 'contribution_id', 'contribution.total_amount', 'contribution.receive_date')
    // ->addWhere('remote_donation_id', 'IS NOT NULL')
    // ->addJoin('Contribution AS contribution', 'INNER', ['contribution_id', '=', 'contribution.id'])
    // ->execute()
    // ->indexBy('remote_donation_id')
    // ->getArrayCopy();

    // Create fixture: 2 donations.
    // Don't run the test twice in one second, or this won't work ;-)
    $sets = [
      ['amount' => '1.23', 'when' => date('Y-m-d\TH:i:s\Z') ],
      ['amount' => '3.45', 'when' => date('Y-m-d\TH:i:s\Z', strtotime('now - 1 day')) ],
    ];
    $createdRemoteDonationIds = [];
    foreach ($sets as $set) {
      $startTime = microtime(TRUE);
      $remoteDonationToday = new RemoteDonation(static::$system);
      $recipients = [['display_name' => 'Test recipient financial type', 'amount' => $set['amount']]];
      $remoteDonationToday->recipients->set($recipients);
      $remoteDonationToday->createdDate->set($set['when']);
      $remoteDonationToday->setDonor(self::$testRemotePerson);
      $remoteDonationToday->setFundraisingPage(self::$testFundraisingPage);
      $remoteDonationToday->recurrence->set(['recurring' => FALSE]);
      $remoteDonationToday->save();
      $createdRemoteDonationIds[] = $remoteDonationToday->getId();
      Logger::logDebug(sprintf('Took %0.1f to create a donation', microtime(TRUE) - $startTime));
    }
    $this->assertCount(count($sets), $createdRemoteDonationIds, 'Failed creating remote fixture');

    // Call system under test.
    /** @var \Civi\Osdi\ActionNetwork\BatchSyncer\DonationBasic */
    $batchSyncer = $this->getBatchSyncer();
    $startTime = microtime(TRUE);
    $batchSyncer->batchSyncFromRemote();
    Logger::logDebug(sprintf('Took %0.1f to call the donation sync’s batchSyncFromRemote', microtime(TRUE) - $startTime));

    // We should now have the donation we created above. (We may have a load of others,
    // too, if you have run this test a few times within a week).
    // $syncState = \Civi\Api4\OsdiDonationSyncState::get(FALSE)
    // ->addSelect('remote_donation_id', 'contribution_id')
    // ->execute()
    // ->getArrayCopy();
    // print "\nReally all: \n" . json_encode($syncState, JSON_PRETTY_PRINT) . "\n";
    //
    // $syncState = \Civi\Api4\OsdiDonationSyncState::get(FALSE)
    // ->addSelect('remote_donation_id', 'contribution_id')
    // ->addWhere('remote_donation_id', 'IN', $createdRemoteDonationIds)
    // ->execute()
    // ->getArrayCopy();
    // print "\nall: \n" . json_encode($syncState, JSON_PRETTY_PRINT) . "\n";

    $syncState = \Civi\Api4\OsdiDonationSyncState::get(FALSE)
    ->addSelect('remote_donation_id', 'contribution_id', 'contribution.total_amount', 'contribution.receive_date')
    ->addJoin('Contribution AS contribution', 'INNER', ['contribution_id', '=', 'contribution.id'])
    ->addWhere('remote_donation_id', 'IN', $createdRemoteDonationIds)
    ->execute()
    ->indexBy('remote_donation_id')
    ->getArrayCopy();
    // print "\nwith contribs: \n" . json_encode($syncState, JSON_PRETTY_PRINT) . "\n";

    foreach ($createdRemoteDonationIds as $i => $remoteId) {
      $this->assertArrayHasKey($remoteId, $syncState, "Missing remote id in dataset $i: $remoteId");
      $this->assertEquals($sets[$i]['amount'], $syncState[$remoteId]['contribution.total_amount'], "Mismatch of amount on dataset $i");
      $this->assertEquals(strtr($sets[$i]['when'], ['Z' => '', 'T'=> ' ']), $syncState[$remoteId]['contribution.receive_date'], "Mismatch of receive date on dataset $i");
    }
    //
    //
    // $contributions = \Civi\Api4\Contribution::get(FALSE)
    //   ->addWhere('contact_id', '=', static::$createdEntities['Contact'][0])
    //   ->execute()->getArrayCopy();
    // $found = 0;
    // foreach ($sets as $set) {
    //   foreach ($contributions as $contribution) {
    //     if ($contribution['total_amount'] == $set['amount']
    //       && $contribution['receive_date'] === strtr($set['when'], ['Z' => '', 'T'=> ' '])) {
    //       $found++;
    //     }
    //     // else {
    //     //   print "cn~f $contribution[total_amount]~$set[amount] "
    //     //   . (($contribution['total_amount'] == $set['amount']) ? 'ok ' : 'no ');
    //     //   print "     '$contribution[receive_date]'~'" . strtr($set['when'], ['Z' => '', 'T'=> ' ']) . "' "
    //     //   . (($contribution['receive_date'] === strtr($set['when'], ['Z' => '', 'T'=> ' '])) ? 'ok' : 'no') . "\n";
    //     // }
    //   }
    // }
    // $this->assertEquals(2, $found);
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
      // else {
      //   print "not this one " . $donation->amount->get() ." nope.\n";
      // }
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

