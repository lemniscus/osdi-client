<?php

namespace Civi\Osdi\ActionNetwork\BatchSyncer;

use Civi;
use Civi\Osdi\Factory;
use Civi\Osdi\ActionNetwork\DonationHelperTrait;
use Civi\Osdi\ActionNetwork\Object\Donation as RemoteDonation;
use Civi\Osdi\ActionNetwork\Matcher\Donation\Basic as DonationBasicMatcher;
use Civi\Osdi\ActionNetwork\Mapper\DonationBasic as DonationBasicMapper;
use Civi\Api4\Contribution;

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

    // Create fixture
    // Don't run the test twice in one second, or this won't work ;-)
    $rightHereRightNow = date('Y-m-d\TH:i:s\Z');
    $remoteDonationToday = new RemoteDonation(static::$system);
    $remoteDonationToday->currency->set('USD');
    $recipients = [['display_name' => 'Test recipient financial type', 'amount' => '2.22']];
    $remoteDonationToday->recipients->set($recipients);
    $remoteDonationToday->createdDate->set($rightHereRightNow);
    $remoteDonationToday->setDonor(self::$testRemotePerson);
    $remoteDonationToday->setFundraisingPage(self::$testFundraisingPage);
    $remoteDonationToday->recurrence->set(['recurring' => FALSE]);
    $remoteDonationToday->save();

    // Call system under test.
    /** @var \Civi\Osdi\ActionNetwork\BatchSyncer\Donation\DonationBasic */
    $batchSyncer = $this->getBatchSyncer();
    $batchSyncer->batchSyncFromRemote();

    // We should now have the donation we created above. (We may have a load of others,
    // too, if you have run this test a few times within a week).
    $contributions = \Civi\Api4\Contribution::get(FALSE)
    ->addWhere('contact_id', '=', static::$createdEntities['Contact'][0])
    ->addWhere('receive_date', '=', rtrim($rightHereRightNow, 'Z'))
    ->execute();
    $this->assertEquals(1, $contributions->countFetched());
    $cn = $contributions->first();
    $this->assertEquals(2.22, $cn['total_amount'], "Amount of Contribution created by Remote-to-Local sync differs.");

  }

  public function testBatchSyncFromCivi() {
    // Create a donation in Civi, call sync, load recent donations and check it's there.
    $now = time();

    // Create fixture.
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
      else {
        print "not this one " . $donation->amount->get() ." nope.\n";
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

