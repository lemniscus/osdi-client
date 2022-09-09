<?php
namespace Civi\Osdi\ActionNetwork\Object;

use Civi\Osdi\ActionNetwork\Object\Donation;
use Civi\Osdi\ActionNetwork\Object\Person;
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

  private static FundraisingPage $fundraisingPage;

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

  // @todo
  public function testNewDonation() {

    // We need a donor.
    $person = new Person($this->system);
    // $person->givenName->set('Generous');
    // $person->familyName->set('McTest');
    $person->emailAddress->set('generous@example.org');
    $personId = $person->save()->getId();

    $donation = new Donation($this->system);
    self::assertEmpty($donation->getId());

    // Set minimal data on donation.
    $donation->currency->set('USD');
    $donation->recipients->set(['display_name' => 'Test recipient', 'amount' => '1.00']);
    $donation->payment->set(['method' => 'Credit Card', 'reference_number' => 'test_payment_1']);
    $donation->recurrence->set(['recurring' => FALSE, /* 'period' => 'Monthly' */]);
    $donation->donor->set($personId);
    $donation->setFundraisingPage(self::$fundraisingPage);
    $donation->setDonor($person);
    // $donation->referrerData->set();
    $id = $donation->save()->getId();

    self::assertNotEmpty($id);

    $reFetchedDonation = Donation::loadFromId($id, $this->system);
    self::assertEquals('USD', $reFetchedDonation->currency->get());

    // Try to delete the things we made. (we probably can't)
    $person->delete();
    $donation->delete();
  }

}

