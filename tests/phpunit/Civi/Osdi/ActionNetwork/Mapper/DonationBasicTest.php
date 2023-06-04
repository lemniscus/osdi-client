<?php

namespace Civi\Osdi\ActionNetwork\Mapper;

use Civi\Core\HookInterface;
use Civi\Osdi\ActionNetwork\DonationHelperTrait;
use Civi\Osdi\ActionNetwork\Mapper\DonationBasic as DonationBasicMapper;
use Civi\Osdi\ActionNetwork\Object\Donation as RemoteDonation;
use Civi\Osdi\LocalObject\DonationBasic as LocalDonation;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use OsdiClient\FixtureHttpClient;

/**
 * Test \Civi\Osdi\RemoteSystemInterface
 *
 * @group headless
 */
class DonationBasicTest extends \PHPUnit\Framework\TestCase implements
    HeadlessInterface,
    HookInterface,
    TransactionalInterface {

  use DonationHelperTrait;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    $this->mapper = new DonationBasicMapper(static::$system);
    parent::setUp();
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  /**
   *
   * Remote → Local
   *
   */
  public function testMapRemoteToNewLocal() {
    $personPair = $this->createInSyncPerson();
    $contactId = $personPair->getLocalObject()->getId();

    // Create fixture
    $remoteDonation = new RemoteDonation(static::$system);
    $remoteDonation->currency->set('USD');
    $recipients = [['display_name' => 'Test recipient financial type', 'amount' => '2.22']];
    $remoteDonation->recipients->set($recipients);
    $remoteDonation->createdDate->set('2020-03-04T05:06:07Z');
    $remoteDonation->setDonor($personPair->getRemoteObject());
    $remoteDonation->setFundraisingPage(self::$testFundraisingPage);
    $remoteDonation->recurrence->set(['recurring' => FALSE]);
    $referrerData = [
      'source' => 'phpunit_source',
      'website' => 'https://example.org/test-referrer',
    ];
    $remoteDonation->referrerData->set($referrerData);
    $remoteDonation->save();

    // Call system under test
    $localDonation = $this->mapper->mapRemoteToLocal($remoteDonation);
    $localDonation->save();

    // Check expectations
    $this->assertEquals($contactId, $localDonation->contactId->get());
    $this->assertEquals('2020-03-04T05:06:07Z', $localDonation->receiveDate->get());
    $this->assertEquals('USD', $localDonation->currency->get());
    $this->assertEquals(static::$financialTypeId, $localDonation->financialTypeId->get());
    $this->assertNull($localDonation->contributionRecurId->get());
    $this->assertEquals(self::$testFundraisingPage->title->get(), $localDonation->source->get());
    $expectedPaymentInstrumentID = \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'payment_instrument_id', 'Credit Card');
    $this->assertEquals($expectedPaymentInstrumentID, $localDonation->paymentInstrumentId->get());

    // We expect payment instrument label to be null as it is only populated when loaded from db.
    $this->assertNull($localDonation->paymentInstrumentLabel->get());
    $localDonation->load();
    $this->assertEquals('Credit Card', $localDonation->paymentInstrumentLabel->get());

  }

  /**
   *
   * Local → Remote
   *
   */
  public function testMapLocalToNewRemote() {

    // Create fixture: create a local donation.
    $donationId = $this->createTestContribution();
    $localDonation = new LocalDonation($donationId);

    // Call system under test
    $remoteDonation = $this->mapper->mapLocalToRemote($localDonation);

    // Check expectations
    $this->assertNotEmpty($remoteDonation->donorHref->get());
    $this->assertNotEmpty($remoteDonation->fundraisingPageHref->get());
    $this->assertEquals(DonationBasicMapper::FUNDRAISING_PAGE_NAME, $remoteDonation->getFundraisingPage()->title->get());
    $this->assertEquals('2022-03-04', substr($remoteDonation->createdDate->get(), 0, 10));
    $this->assertEquals('USD', $remoteDonation->currency->get());
    $this->assertEquals([['display_name' => 'Donation', 'amount' => 1.23]], $remoteDonation->recipients->get());
    $this->assertEquals(['recurring' => FALSE], $remoteDonation->recurrence->get());
  }
  protected function createTestContribution(): int {
    $personPair = $this->createInSyncPerson();
    $contactId = $personPair->getLocalObject()->getId();
    // Create a local Contribution.
    $contributionId = civicrm_api3('Order', 'create', [
      'contact_id' => $contactId,
      'financial_type_id' => 1, // donation
      'total_amount' => '1.23',
      'receive_date' => '2022-03-04',
      'line_items' => [
        [
          'line_item' => [[
            'line_total' => '1.23',
            'price_field_id' => 1,
            'price_field_value_id' => 1,
          ]]
        ]
      ],
      'payment_method_id' => 1, // credit card
      'currency' => 'USD', // credit card
    ])['id'];
    civicrm_api3('Payment', 'create', [
      'contribution_id' => $contributionId,
      'total_amount' => '1.23',
      'trxn_id' => 'test_trxn_1',
      'trxn_date' => '2022-03-04',
    ]);

    return (int) $contributionId;
  }

}

