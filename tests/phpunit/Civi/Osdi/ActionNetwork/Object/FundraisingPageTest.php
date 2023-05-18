<?php
namespace Civi\Osdi\ActionNetwork\Object;

use Civi\Core\HookInterface;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use OsdiClient\ActionNetwork\TestUtils;
use OsdiClient\FixtureHttpClient;

/**
 * @group headless
 */
class FundraisingPageTest extends \PHPUnit\Framework\TestCase implements
  HeadlessInterface,
  HookInterface,
  TransactionalInterface {

  const FUNDRAISING_PAGE_NAME = 'CiviCRM Contributions (TEST)';

  /**
   * @var \Civi\Osdi\ActionNetwork\RemoteSystem
   */
  private $system;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    $this->system = TestUtils::createRemoteSystem();
    FixtureHttpClient::resetHistory();
    parent::setUp();
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  public function testCreateFundraisingPage() {
    $prefix = 'CiviCRM test fundraising page ' . date('YmdHis') . '-';
    $maxSuffix = 0;

    // Ensure the test page does not exist.
    $fundraisingPages = $this->system->findAll('osdi:fundraising_pages');
    foreach ($fundraisingPages as $fundraisingPage) {
      if (preg_match("/$prefix(\d+)$/", $fundraisingPage->title->get(), $matches)) {
        $suffix = (int) $matches[1];
        $maxSuffix = max($suffix, $maxSuffix);
      }
    }
    $newPageTitle = $prefix . ($maxSuffix + 1);

    // Create thepage
    $fundraisingPage = new FundraisingPage($this->system);
    $fundraisingPage->name->set($newPageTitle);
    // Nb. title is the public title and is required according to the API,
    // even though there should not be a public page for API-created
    // fundraising pages.
    $fundraisingPage->title->set($newPageTitle);
    $fundraisingPage->origin_system->set('CiviCRM');
    $fundraisingPage->save();

    // Fetch it.
    $fundraisingPageId = $fundraisingPage->getId();
    $fundraisingPage = FundraisingPage::loadFromId($fundraisingPageId, $this->system);

    $this->assertEquals($fundraisingPageId, $fundraisingPage->getId());
    $this->assertEquals($newPageTitle, $fundraisingPage->name->get());
    $this->assertEquals($newPageTitle, $fundraisingPage->title->get());
    $this->assertEquals('CiviCRM', $fundraisingPage->origin_system->get());
  }

}


