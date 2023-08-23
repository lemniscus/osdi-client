<?php
namespace Civi\Osdi\ActionNetwork;

use Civi\Api4\FinancialType;
use Civi\Osdi\ActionNetwork\Mapper\DonationBasic as DonationBasicMapper;
use Civi\Osdi\ActionNetwork\Object\FundraisingPage;
use Civi\Osdi\ActionNetwork\Object\Person;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Logger;
use Civi\OsdiClient;
use OsdiClient\ActionNetwork\PersonMatchingFixture;


trait DonationHelperTrait {

  protected static RemoteSystem $system;

  protected static FundraisingPage $testFundraisingPage;

  protected static int $financialTypeId;

  public function createInSyncPerson(): LocalRemotePair {
    $remotePerson = PersonMatchingFixture::saveNewUniqueRemotePerson();
    Logger::logDebug("New test person: {$remotePerson->getId()}, "
      . $remotePerson->emailAddress->get());

    // ... use sync to create local person
    /** @var \Civi\Osdi\ActionNetwork\SingleSyncer\PersonBasic $personSyncer */
    $personSyncer = OsdiClient::container()->getSingle('SingleSyncer', 'Person');
    $pair = $personSyncer->matchAndSyncIfEligible($remotePerson);
    self::assertFalse($pair->isError());

    return $pair;
  }

  private static function getDefaultFundraisingPage(): FundraisingPage {
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
      $fundraisingPage->originSystem->set('CiviCRM');
      $fundraisingPage->save();
    }
    return $fundraisingPage;
  }

  private static function getTestFinancialTypeId(): int {
    // Ensure we have 'Test recipient financial type'
    $ftName = 'Test recipient financial type';
    $financialTypeApiResult = FinancialType::get(FALSE)
      ->addWhere('name', '=', $ftName)
      ->execute();

    if ($financialTypeApiResult->count() > 1) {
      //throw new \Exception("The DB has multiple financial types by the same name: $ftName");
      $duplicateIds = $financialTypeApiResult->column('id');
      $keptId = array_pop($duplicateIds);
      FinancialType::delete(FALSE)
        ->addWhere('id', 'IN', $duplicateIds)
        ->execute();
      return $keptId;
    }
    elseif ($financialTypeApiResult->count() === 0) {
      $financialTypeApiResult = FinancialType::create(FALSE)
        ->addValue('name', $ftName)
        ->addValue('description', 'Used by PHPUnit test ' . __CLASS__ . '::' . __FUNCTION__)
        ->execute();
    }
    return $financialTypeApiResult->single()['id'];
  }

}
