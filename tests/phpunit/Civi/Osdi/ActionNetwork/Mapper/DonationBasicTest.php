<?php

namespace Civi\Osdi\ActionNetwork\Mapper;

use Civi;
use Civi\Test\HeadlessInterface;
use Civi\Core\HookInterface;
use Civi\Osdi\ActionNetwork\Mapper\DonationBasic as DonationBasicMapper;
use Civi\Osdi\LocalObject\Donation as LocalDonation;
use Civi\Osdi\ActionNetwork\Matcher\Person\UniqueEmailOrFirstLastEmail as PersonMatcher;
use Civi\Osdi\ActionNetwork\Object\FundraisingPage;

use Civi\Test\TransactionalInterface;
use CRM_OSDI_ActionNetwork_TestUtils;
use CRM_OSDI_FixtureHttpClient;

/**
 * Test \Civi\Osdi\RemoteSystemInterface
 *
 * @group headless
 */
class DonationBasicTest extends \PHPUnit\Framework\TestCase implements
  HeadlessInterface,
  HookInterface,
  TransactionalInterface {

  /**
   * @var array{Contact: array, OptionGroup: array, OptionValue: array,
   *   CustomGroup: array, CustomField: array}
   */
  private static $createdEntities = [];

  private static Civi\Osdi\ActionNetwork\RemoteSystem $system;

  private static Civi\Osdi\ActionNetwork\Object\Person $testRemotePerson;

  private static \Civi\Osdi\ActionNetwork\Matcher\Person\UniqueEmailOrFirstLastEmail $personMatcher;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public function setUp(): void {

    $personMatcher = new PersonMatcher(static::$system);
    $this->mapper = new DonationBasicMapper(static::$system, $personMatcher);
    CRM_OSDI_FixtureHttpClient::resetHistory();
    parent::setUp();
  }

  public static function setUpBeforeClass(): void {
  
    static::$system = CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
    // We need a remote person that matches a local person.
    $remotePerson = new Civi\Osdi\ActionNetwork\Object\Person(static::$system);
    $remotePerson->givenName->set('Wilma');
    $remotePerson->familyName->set('Flintstone');
    $remotePerson->emailAddress->set('wilma@example.org');
    $remotePerson->save();
    static::$testRemotePerson = $remotePerson;

    $personSyncer = new \Civi\Osdi\ActionNetwork\SingleSyncer\Person\PersonBasic(static::$system);
    $personMapper = new \Civi\Osdi\ActionNetwork\Mapper\PersonBasic(static::$system);
    $personSyncer->setMapper($personMapper);
    static::$personMatcher = new \Civi\Osdi\ActionNetwork\Matcher\Person\UniqueEmailOrFirstLastEmail(static::$system);
    $personSyncer->setMatcher(static::$personMatcher);
    $syncResult = $personSyncer->syncFromRemoteIfNeeded($remotePerson);
    static::$createdEntities['Contact'] = [$syncResult->getLocalObject()->getId()];

    // Ensure we have the default fundraising page.
    $fundraisingPages = static::$system->findAll('osdi:fundraising_pages');
    $found = FALSE;
    foreach ($fundraisingPages as $fundraisingPage) {
      if ($fundraisingPage->title->get() === DonationBasicMapper::FUNDRAISING_PAGE_NAME) {
        $found = $fundraisingPage;
        break;
      }
    }
    if (!$found) {
      // Create the default fundraising page now.
      // @Todo expect this code to change.
      
      $fundraisingPage = new FundraisingPage(static::$system);
      $fundraisingPage->name->set(DonationBasicMapper::FUNDRAISING_PAGE_NAME);
      // Nb. title is the public title and is required according to the API,
      // even though there should not be a public page for API-created
      // fundraising pages.
      $fundraisingPage->title->set(DonationBasicMapper::FUNDRAISING_PAGE_NAME);
      $fundraisingPage->origin_system->set('CiviCRM');
      $fundraisingPage->save();
    }

  }

  public function tearDown(): void {
    parent::tearDown();
  }

  public static function tearDownAfterClass(): void {

    static::$testRemotePerson->delete();

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

  /**
   *
   * LOCAL → REMOTE
   *
   */
  public function testMapLocalToNewRemote() {

    $donationId = $this->createTestContribution();
    $localDonation = new LocalDonation($donationId);
    $localDonation->loadOnce();
    $mapper = new DonationBasicMapper(static::$system, static::$personMatcher);
    $remoteDonation = $mapper->mapLocalToRemote($localDonation);

    $this->assertNotEmpty($remoteDonation->donorHref->get());
    $this->assertNotEmpty($remoteDonation->fundraisingPageHref->get());
    $this->assertEquals('2022-03-04', substr($remoteDonation->createdDate->get(), 0, 10));
    $this->assertEquals('USD', $remoteDonation->currency->get());
    $this->assertEquals([['display_name' => 'Donation', 'amount' => 1.23]], $remoteDonation->recipients->get());
    $this->assertEquals(['recurring' => FALSE], $remoteDonation->recurrence->get());
  }

  protected function createTestContribution(): int {
    $contactId = static::$createdEntities['Contact'][0];
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

