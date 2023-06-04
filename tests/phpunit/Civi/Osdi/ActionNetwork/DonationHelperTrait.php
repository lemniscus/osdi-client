<?php
namespace Civi\Osdi\ActionNetwork;

use Civi\Api4\FinancialType;
use Civi\Osdi\ActionNetwork\Mapper\DonationBasic as DonationBasicMapper;
use Civi\Osdi\ActionNetwork\Matcher\Person\UniqueEmailOrFirstLastEmail;
use Civi\Osdi\ActionNetwork\Object\FundraisingPage;
use Civi\Osdi\ActionNetwork\Object\Person;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Logger;
use Civi\OsdiClient;


trait DonationHelperTrait {

  private static RemoteSystem $system;

  private static UniqueEmailOrFirstLastEmail $personMatcher;

  private static FundraisingPage $testFundraisingPage;

  private static int $financialTypeId;

  /**
   * Create a remote person, held in static::$testRemotePerson
   * Sync it to local, contact ID in static::$createdEntities['Contact'][0]
   * Ensure test fundraising page in static::$fundraisingPage
   * Create test recipient financial type in static::$financialTypeId
   */
  public static function setUpBeforeClass(): void {
    static::$system = \OsdiClient\ActionNetwork\TestUtils::createRemoteSystem();
    static::$personMatcher = new UniqueEmailOrFirstLastEmail(static::$system);

    \OsdiClient\ActionNetwork\TestUtils::createSyncProfile();


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
    static::$testFundraisingPage = $fundraisingPage;

    // Create 'Test recipient financial type'
    $ftName = 'Test recipient financial type';
    $financialTypeApiResult = FinancialType::get(FALSE)
      ->addWhere('name', '=', $ftName)
      ->execute();

    if ($financialTypeApiResult->count() > 1) {
      throw new \Exception("The DB has multiple financial types by the same name: $ftName");
    }
    elseif ($financialTypeApiResult->count() === 0) {
      $financialTypeApiResult = \Civi\Api4\FinancialType::create(FALSE)
        ->addValue('name', $ftName)
        ->addValue('description', 'Used by PHPUnit test ' . __CLASS__ . '::' . __FUNCTION__)
        ->execute();
    }
    static::$financialTypeId = $financialTypeApiResult->single()['id'];
  }

  public function createInSyncPerson(): LocalRemotePair {
    static $count = 0;
    $count++;

    // We need a remote person that matches a local person.
    $remotePerson = new Person(static::$system);
    $remotePerson->givenName->set('Wilma');
    $remotePerson->familyName->set('FlintstoneTest');
    // Use an email the system won't have seen before, so we are sure we have a new contact.
    $email = "wilma$count." . (new \DateTime())->format('Ymd.Hisv') . '@example.org';
    $remotePerson->emailAddress->set($email);
    $remotePerson->save();
    Logger::logDebug("New test person: {$remotePerson->getId()}, $email");

    // ... use sync to create local person
    /** @var \Civi\Osdi\ActionNetwork\SingleSyncer\PersonBasic $personSyncer */
    $personSyncer = OsdiClient::container()->getSingle('SingleSyncer', 'Person');
    $pair = $personSyncer->matchAndSyncIfEligible($remotePerson);
    self::assertFalse($pair->isError());

    return $pair;
  }

}
