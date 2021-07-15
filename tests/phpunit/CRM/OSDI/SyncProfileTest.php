<?php
/** @noinspection PhpFullyQualifiedNameUsageInspection */

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test \Civi\Osdi\RemoteSystemInterface
 *
 * @group headless
 */
class CRM_OSDI_SyncProfileTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface,
                                                                                       TransactionalInterface
{
    public function setUpHeadless(): \Civi\Test\CiviEnvBuilder
    {
        return \Civi\Test::headless()->installMe(__DIR__)->apply();
    }

    public function setUp(): void
    {
        CRM_OSDI_FixtureHttpClient::resetHistory();
        parent::setUp();
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    public function testHasNeededFields()
    {
      $fields = \Civi\Api4\OsdiSyncProfile::getFields(FALSE)->execute()->column('name');
      self::assertContains('id', $fields);
      self::assertContains('is_default', $fields);
      self::assertContains('label', $fields);
      self::assertContains('remote_system', $fields);
      self::assertContains('entry_point', $fields);
      self::assertContains('api_token', $fields);
      self::assertContains('matcher', $fields);
      self::assertContains('mapper', $fields);
    }

    public function testNextThing()
    {
      self::fail();
    }
}