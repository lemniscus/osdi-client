<?php

namespace Civi\Osdi\ActionNetwork\BatchSyncer;

use Civi\Osdi\ActionNetwork\DonationHelperTrait;
use Civi\Osdi\ActionNetwork\Object\Donation as RemoteDonation;
use Civi\Osdi\ActionNetwork\Matcher\Donation\Basic as DonationBasicMatcher;
use Civi\Osdi\ActionNetwork\Mapper\DonationBasic as DonationBasicMapper;

use Civi\OsdiClient;
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
    $personPair = $this->createInSyncPerson();
    $contactId = $personPair->getLocalObject()->getId();

    // Create fixture: 2 donations.
    // Don't run the test twice in one second, or this won't work ;-)
    $sets = [
      ['amount' => '1.23', 'when' => date('Y-m-d\TH:i:s\Z') ],
      ['amount' => '3.45', 'when' => date('Y-m-d\TH:i:s\Z', strtotime('now - 1 day')) ],
      ['amount' => '7.89', 'when' => date('Y-m-d\TH:i:s\Z', strtotime('now - 1 month')) ],
    ];
    $createdRemoteDonationIds = [
      $this->createRemoteDonationAndGetId($sets[0], $personPair->getRemoteObject()),
      $this->createRemoteDonationAndGetId($sets[1], $personPair->getRemoteObject()),
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
    $countOfDonations = \Civi\Api4\Contribution::get(FALSE)->selectRowCount()->addWhere('contact_id', '=', $contactId)->addWhere('is_test', 'IN', [0, 1])->execute()->count();
    $this->assertEquals(2, $countOfDonations, "Expected 2 contributions, but got $countOfDonations");

    // Part 2: create third donation.
    $createdRemoteDonationIds[] = $this->createRemoteDonationAndGetId($sets[2], $personPair->getRemoteObject());
    $batchSyncer->batchSyncFromRemote();

    $newCountOfDonations = \Civi\Api4\Contribution::get(FALSE)->selectRowCount()->addWhere('contact_id', '=', $contactId)->addWhere('is_test', 'IN', [0, 1])->execute()->count();
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

  protected function createRemoteDonationAndGetId(array $set, $remotePerson): string {
      $remoteDonationToday = new RemoteDonation(static::$system);
      $recipients = [['display_name' => 'Test recipient financial type', 'amount' => $set['amount']]];
      $remoteDonationToday->recipients->set($recipients);
      $remoteDonationToday->createdDate->set($set['when']);
      $remoteDonationToday->setDonor($remotePerson);
      $remoteDonationToday->setFundraisingPage(self::$testFundraisingPage);
      $remoteDonationToday->recurrence->set(['recurring' => FALSE]);
      $remoteDonationToday->save();
      return $remoteDonationToday->getId();
  }

  public function testBatchSyncFromCivi() {
    [$remotePersonOneId, $now, $localSets, $createdContributionIds]
      = $this->setUpCiviToAnSyncFixture();

    $batchSyncer = $this->getBatchSyncer();

    // Call system under test
    $batchSyncer->batchSyncFromLocal();

    // Checks
    $this->assertRemoteDonationsMatch(2, $now, $localSets, $createdContributionIds,
      $remotePersonOneId);

    // Now add one more contribution and repeat.
    $createdContributionIds[] = $this->createLocalContribution(...$localSets[2]);

    // Call system under test
    $batchSyncer->batchSyncFromLocal();

    // Checks
    $this->assertRemoteDonationsMatch(3, $now, $localSets, $createdContributionIds,
      $remotePersonOneId);
  }

  public function testBatchSyncViaApiCall() {
    [$remotePersonOneId, $now, $localSets, $createdContributionIds]
      = $this->setUpCiviToAnSyncFixture();

    // Call system under test
    $syncProfileId = \OsdiClient\ActionNetwork\TestUtils::createSyncProfile()['id'];
    $result = civicrm_api3('Job', 'osdiclientbatchsyncdonations',
      ['debug' => 1, 'origin' => 'local', 'sync_profile_id' => $syncProfileId]);

    // Checks
    $this->assertRemoteDonationsMatch(2, $now, $localSets,
      $createdContributionIds, $remotePersonOneId);

    // Now add one more contribution and repeat.
    $createdContributionIds[] = $this->createLocalContribution(...$localSets[2]);

    // Call system under test
    $result = civicrm_api3('Job', 'osdiclientbatchsyncdonations',
      ['debug' => 1, 'origin' => 'local', 'sync_profile_id' => $syncProfileId]);

    // Checks
    $this->assertRemoteDonationsMatch(3, $now, $localSets,
      $createdContributionIds, $remotePersonOneId);
  }


  protected function createLocalContribution(array $orderParams, array $paymentParams, int $contactId): int {

    $orderParams += [
      'receive_date' => date('Y-m-d H:i:s', strtotime('now - 1 day')),
      'financial_type_id' => 1,
      'contact_id' => $contactId,
      'total_amount' => 1.23,
    ];
    $contribution = civicrm_api3('Order', 'create', $orderParams);

    $paymentParams += [
      'contribution_id' => $contribution['id'],
      'total_amount' => $orderParams['total_amount'],
      'trxn_date' => $orderParams['receive_date'],
      'trxn_id' => 'abc',
    ];
    civicrm_api3('Payment', 'create', $paymentParams);

    return $contribution['id'];
  }
  protected function assertRemoteDonationsMatch(int $expectedCount, int $now, array $sets, array $createdContributionIds, string $remotePersonId) {
    $contributionIdToSetNo = array_flip($createdContributionIds);
    // Load all our donation sync status records
    $remoteIdToContributionId = \Civi\Api4\OsdiDonationSyncState::get(FALSE)
    ->addSelect('remote_donation_id', 'contribution_id')
    ->addWhere('contribution_id', 'IN', $createdContributionIds)
    ->execute()
    ->indexBy('remote_donation_id')
    ->column('contribution_id');
    $this->assertCount($expectedCount, $remoteIdToContributionId);

    $donations = static::$system->find('osdi:donations', [
      ['modified_date', 'gt', date('Y-m-d\TH:i:s\Z', $now - 60)],
    ]);
    $found = 0;
    /** @var RemoteDonation $donation */
    foreach ($donations as $donation) {
      if (strpos($donation->donorHref->get(), $remotePersonId) === FALSE) {
        // This donation is from a previous test as it does not belong to our contact: ignore it.
        continue;
      }
      $donationId = $donation->getId();

      $_ = $donation->amount->get() . ' ' . $donation->createdDate->get();
      $this->assertArrayHasKey($donationId, $remoteIdToContributionId, "A remote donation $_ exists for our test contact that we do not have a sync state for.");
      $set = $sets[$contributionIdToSetNo[$remoteIdToContributionId[$donationId]]];
      // Check the amount, date matches.
      $this->assertEquals($set[0]['total_amount'], $donation->amount->get());
      $this->assertEquals($set[0]['receive_date'], strtr($donation->createdDate->get(), ['Z' => '', 'T'=> ' ']));
      $found++;
    }
    $this->assertEquals($expectedCount, $found);
  }


  protected function getBatchSyncer(): \Civi\Osdi\ActionNetwork\BatchSyncer\DonationBasic {
    $container = OsdiClient::container();
    // Call system under test.
    /** @var \Civi\Osdi\ActionNetwork\SingleSyncer\Donation\DonationBasic */
    $singleSyncer = $container->make('SingleSyncer', 'Donation', self::$system);
    $matcher = $container->make('Matcher', 'Donation');
    $singleSyncer->setMatcher($matcher);
    $mapper = $container->make('Mapper', 'Donation');
    $singleSyncer->setMapper($mapper);
    /** @var \Civi\Osdi\ActionNetwork\BatchSyncer\DonationBasic **/
    $batchSyncer = $container->make('BatchSyncer', 'Donation', $singleSyncer);

    return $batchSyncer;
  }

  private function setUpCiviToAnSyncFixture(): array {
    $personPair = $this->createInSyncPerson();
    $contactId = $personPair->getLocalObject()->getId();
    $remotePersonId = $personPair->getRemoteObject()->getId();

    $now = time();
    $yesterday = date('Y-m-d H:i:s', $now - 60 * 60 * 24 * 1);

    $sets = [
      [
        ['total_amount' => 1.23, 'receive_date' => $yesterday],
        ['trxn_id' => 'testtrxn_1'],
        $contactId,
      ],
      [
        ['total_amount' => 4.56, 'receive_date' => $yesterday],
        ['trxn_id' => 'testtrxn_2'],
        $contactId,
      ],
      [
        ['total_amount' => 7.89, 'receive_date' => $yesterday],
        ['trxn_id' => 'testtrxn_3'],
        $contactId,
      ],
    ];

    // Create donations in Civi, call sync, load recent donations and check it's there.
    $createdContributionIds = [
      $this->createLocalContribution(...$sets[0]),
      $this->createLocalContribution(...$sets[1]),
    ];

    return array($remotePersonId, $now, $sets, $createdContributionIds);
  }

}

