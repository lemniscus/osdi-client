<?php

namespace Civi\Osdi\ActionNetwork\BatchSyncer;

use Civi;
use Civi\Osdi\Factory;
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

    // We must:
    // - add a donation from a year ago on AN.
    // - add a donation from today on AN.
    // - call sync
    // - expect the latter local donation to be created, but not the first.

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
    /** @var \Civi\Osdi\ActionNetwork\SingleSyncer\Donation\DonationBasic */
    $singleSyncer = Factory::singleton('SingleSyncer', 'Donation', self::$system);
    $matcher = new DonationBasicMatcher();
    $singleSyncer->setMatcher($matcher);
    $mapper = new DonationBasicMapper(static::$system, static::$personMatcher);
    $singleSyncer->setMapper($mapper);
    /** @var \Civi\Osdi\ActionNetwork\BatchSyncer\DonationBasic **/
    $batchSyncer = Factory::singleton('BatchSyncer', 'Donation', $singleSyncer);
    $batchSyncer->batchSyncFromRemote();

    // We should now the donation we created above. (We may have a load of others,
    // too, if you have run this test a few times within a week).
    $contributions = \Civi\Api4\Contribution::get(FALSE)
    ->addWhere('contact_id', '=', static::$createdEntities['Contact'][0])
    ->addWhere('receive_date', '=', rtrim($rightHereRightNow, 'Z'))
    ->execute();
    $this->assertEquals(1, $contributions->countFetched());
    $cn = $contributions->first();
    $this->assertEquals(2.22, $cn['total_amount'], "Amount of Contribution created by Remote-to-Local sync differs.");

  }

  // public function testBatchSyncFromCivi() {
  //   return;
  // }


}

