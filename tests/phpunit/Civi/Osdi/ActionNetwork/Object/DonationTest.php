<?php
namespace Civi\Osdi\ActionNetwork\Object;

use Civi\Osdi\ActionNetwork\Object\Donation;
use Civi\Osdi\ActionNetwork\Object\Person;
use Civi\Osdi\Exception\InvalidOperationException;
use Civi\Osdi\ActionNetwork\Object\FundraisingPage;
use Civi\Osdi\ActionNetwork\RemoteSystem;
use Civi\Core\HookInterface;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use CRM_OSDI_ActionNetwork_TestUtils;
use CRM_OSDI_FixtureHttpClient;

/**
 * @group headless
 */
class DonationTest extends \PHPUnit\Framework\TestCase implements
    HeadlessInterface,
    HookInterface,
    TransactionalInterface {

  const FUNDRAISING_PAGE_NAME = 'CiviCRM Contributions (TEST)';
  /**
   * @var array{Contact: array, Contribution: array}
   */
  private static $createdEntities = [];

  private static ?FundraisingPage $fundraisingPage;

  private static ?Person $donor;

  /**
   * @var \Civi\Osdi\ActionNetwork\RemoteSystem
   */
  private $system;


  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public static function setUpBeforeClass(): void {
    $system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
    self::$fundraisingPage = self::getOrCreateTestFundraisingPage($system);

    // We can re-use the same person in this test.
    $person = new Person($system);
    $person->emailAddress->set('generous@example.org');
    $person->save();
    self::$donor = $person;
  }

  public function setUp(): void {
    $this->system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
    CRM_OSDI_FixtureHttpClient::resetHistory();
    parent::setUp();
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  public static function tearDownAfterClass(): void {
    foreach (self::$createdEntities as $type => $ids) {
      foreach ($ids as $id) {
        civicrm_api4($type, 'delete', [
          'where' => [['id', '=', $id]],
          'checkPermissions' => FALSE,
        ]);
      }
    }

    // Delete the test person.
    self::$donor->delete();
    self::$donor = null;

    parent::tearDownAfterClass();
  }

  // @todo
  public function testGetType() {
    $tag = new Donation($this->system);
    self::assertEquals('osdi:donations', $tag->getType());
  }

  /**
   * Do this once per test run.
   */
  private static function getOrCreateTestFundraisingPage(RemoteSystem $system): FundraisingPage {
    $fundraisingPages = $system->find('osdi:fundraising_pages', []);
    foreach ($fundraisingPages as $fundraisingPage) {
      if ($fundraisingPage->title->get() === self::FUNDRAISING_PAGE_NAME) {
        return $fundraisingPage;
      }
    }

    // Seems submitting a query gives a 500 error; oData queries not supported.
    // $fundraisingPages = $system->find(
    //     'osdi:fundraising_pages',
    //     [['name', 'eq',  'CiviCRM Contributions (TEST)']]);

    // Does not exist yet.
    $fundraisingPage = new FundraisingPage($system);
    $fundraisingPage->name->set(self::FUNDRAISING_PAGE_NAME);
    // Nb. title is the public title and is required according to the API,
    // even though there should not be a public page for API-created
    // fundraising pages.
    $fundraisingPage->title->set(self::FUNDRAISING_PAGE_NAME);
    $fundraisingPage->origin_system->set('CiviCRM');
    $fundraisingPage->save();

    return $fundraisingPage;
  }

  /**
   * Tests that a new donation can be recorded with Action Network.
   *
   * This also tests some of their policies still hold true.
   *
   */
  public function testNewDonation() {

    // Create a Remote Donation object -----------------------------------
    /** @var Donation $donation */
    $donation = new Donation($this->system);
    $this->assertEmpty($donation->getId());
    // Set minimal data on donation.
    // Note: setting currency in normal ISO 4217 is supported, though it gets returned in lowercase.
    $donation->currency->set('USD');
    // Recipient names are CiviCRM Financial Type names. Here we deliberately use a test one.
    $recipients = [['display_name' => 'Test recipient financial type A', 'amount' => '1.00']];
    $donation->recipients->set($recipients);
    // ActionNetwork accepts 'payment', and 'recurrence' keys but ignores it. We test this.
    $paymentInfo = ['method' => 'EFT', 'reference_number' => 'test_payment_1'];
    $donation->payment->set($paymentInfo);
    $donation->recurrence->set(['recurring' => TRUE, 'period' => 'Monthly']);
    // We are permitted to set the createdDate as a way to back date contributions.
    $donation->createdDate->set('2020-03-04T05:06:07Z');
    // Set linked resources.
    $donation->setDonor(self::$donor);
    $donation->setFundraisingPage(static::$fundraisingPage);
    // @todo referrerData does not work as expected.
    $referrerData = [
      'source' => 'phpunit_source',
      'website' => 'https://example.org/osdi-test',
      // 'referrer' => 'phpunit_referrer',
      //               "Must be a valid Action Network referrer code. Read-only. Corresponds to the referrers chart in this action's manage page."
      //               Gets fixed as 'group-civi-sandbox' whatever we send.
    ];
    $donation->referrerData->set($referrerData);

    // Save the object to Action Network ------------------------------------
    $id = $donation->save()->getId();
    $this->assertNotEmpty($id);

    // Fetch object back from Action Network and inspect --------------------
    /** @var Donation $reFetchedDonation */
    $reFetchedDonation = Donation::loadFromId($id, $this->system);
    // print "\nDonation made: " . $reFetchedDonation->getId() . "\n";

    // Note ActionNetwork returns currencies like ISO 4217 but lower case.
    $this->assertEquals('usd', $reFetchedDonation->currency->get());
    $this->assertEquals('2020-03-04T05:06:07Z', $reFetchedDonation->createdDate->get());
    $this->assertEquals($recipients, $reFetchedDonation->recipients->get());

    $fetchedPaymentInfo = $reFetchedDonation->payment->get();
    $this->assertIsArray($fetchedPaymentInfo);
    $this->assertEquals('Credit Card', $fetchedPaymentInfo['method'] ?? NULL,
      "For some reson, ActionNetwork should declare ALL payments submitted through API as Credit Card, even though we passed in EFT. If this test fails, their policy has changed since this code was written.");
    $this->assertNotEquals($paymentInfo['reference_number'], $fetchedPaymentInfo['reference_number'] ?? NULL,
      "For some reson, ActionNetwork should ignore our reference_number and generate its own. If this test fails, their policy has changed since this code was written.");

    $this->assertEquals(['recurring' => TRUE, 'period' => 'Monthly'], $reFetchedDonation->recurrence->get());

    // The amount is generated from the sum of the recipients' amounts.
    $this->assertEquals('1.00', $reFetchedDonation->amount->get());

    // Check the links worked.
    $this->assertEquals(static::$fundraisingPage->getUrlForRead(), $reFetchedDonation->fundraisingPageHref->get());
    $this->assertEquals(self::$donor->getUrlForRead(), $reFetchedDonation->donorHref->get());

    $fetchedReferrerData = $reFetchedDonation->referrerData->get();
    $this->assertEquals($referrerData, $fetchedReferrerData);

    // Try to delete the donation page (check this is disallowed)
    $this->expectException(InvalidOperationException::class);
    $this->expectExceptionMessageMatches('/Objects of type osdi:donations cannot be deleted via the Action Network API/');
    $donation->delete();
  }

}

