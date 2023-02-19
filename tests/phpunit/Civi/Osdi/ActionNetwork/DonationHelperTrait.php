<?php
namespace Civi\Osdi\ActionNetwork;

use Civi\Api4\FinancialType;
use Civi\Osdi\ActionNetwork\Object\Person;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\ActionNetwork\RemoteSystem;
use Civi\Osdi\Logger;
use Civi\Osdi\ActionNetwork\Matcher\Person\UniqueEmailOrFirstLastEmail;
use Civi\Osdi\ActionNetwork\Mapper\DonationBasic as DonationBasicMapper;
use Civi\Osdi\ActionNetwork\Object\FundraisingPage;


trait DonationHelperTrait {

  /**
   * @var array{Contact: array, OptionGroup: array, OptionValue: array,
   *   CustomGroup: array, CustomField: array}
   */
  protected $createdLocalEntities = [];

  protected $createdRemoteEntities = [];

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
    static::$system = \CRM_OSDI_ActionNetwork_TestUtils::createRemoteSystem();
    static::$personMatcher = new UniqueEmailOrFirstLastEmail(static::$system);

    \CRM_OSDI_ActionNetwork_TestUtils::createSyncProfile();


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

  public function tearDown(): void {

    foreach ($this->createdRemoteEntities as $entity) {
      $entity->delete();
    }

    // Civi seems to be refusing to delete contacts, probably because they have contributions.
    // However they should be removed by the db rollback.
    //
    // foreach ($this->createdLocalEntities as $type => $ids) {
    //   // filter these for ones that still exist (actually shouldn't be any?)
    //   $exists = civicrm_api4($type, 'get', [
    //       'select' => ['id'],
    //       'where' => [['id', 'IN', $ids]],
    //       'checkPermissions' => FALSE,
    //     ])->column('id');
    //
    //   foreach ($ids as $id) {
    //     if (in_array($id, $exists)) {
    //       civicrm_api4($type, 'delete', [
    //         'where' => [['id', '=', $id]],
    //         'checkPermissions' => FALSE,
    //         'useTrash' => FALSE,
    //       ]);
    //     }
    //   }
    // }

  }


  public function createInSyncPerson(): LocalRemotePair {

    // We need a remote person that matches a local person.
    Logger::logDebug("Creating new test person as did not find one.");
    $remotePerson = new Person(static::$system);
    $remotePerson->givenName->set('Wilma');
    $remotePerson->familyName->set('FlintstoneTest');
    // Use an email the system won't have seen before, so we are sure we have a new contact.
    $remotePerson->emailAddress->set('wilma.' . (new \DateTime())->format('Ymd.Hisv') . '@example.org');
    $remotePerson->save();
    Logger::logDebug("New test person: " . $remotePerson->getId());

    // ... use sync to create local person
    $personSyncer = new \Civi\Osdi\ActionNetwork\SingleSyncer\Person\PersonBasic(static::$system);
    $personMapper = new \Civi\Osdi\ActionNetwork\Mapper\PersonBasic(static::$system);
    $personSyncer->setMapper($personMapper);
    $personSyncer->setMatcher(static::$personMatcher);
    $pair = $personSyncer->matchAndSyncIfEligible($remotePerson);

    $this->createdLocalEntities['Contact'] = [$pair->getLocalObject()->getId()];
    // $contactId = static::$createdEntities['Contact'][0];

    $this->createdRemoteEntities[] = $remotePerson;

    return $pair;

    // HACK: the above sometimes returns a deleted contact. (shouldn't)
    // $neededToUndelete = \Civi\Api4\Contact::update(FALSE)->addWhere('id', '=', $contactId)->addValue('is_deleted', 0)->addWhere('is_deleted', '=', 1)->execute()->count();
    // print "\nSync created contact $contactId from remote person {$remotePerson->getId()}\n";
  }

}
