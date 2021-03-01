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
class CRM_OSDI_ActionNetwork_MapperTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface,
                                                                                       TransactionalInterface
{
    /**
     * @var \Civi\Osdi\ActionNetwork\RemoteSystem
     */
    private $system;

    /**
     * @var \Civi\Osdi\ActionNetwork\Mapper\Generic
     */
    private $mapper;

    public function setUpHeadless(): \Civi\Test\CiviEnvBuilder
    {
        // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
        // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
        return \Civi\Test::headless()->installMe(__DIR__)->apply();
    }

    public function setUp(): void
    {
        $this->system = $this->createRemoteSystem();
        $this->mapper = $this->createMapper($this->system);
        CRM_OSDI_FixtureHttpClient::resetHistory();
        parent::setUp();
    }

    public function createRemoteSystem(): \Civi\Osdi\ActionNetwork\RemoteSystem
    {
        $systemProfile = new CRM_OSDI_BAO_RemoteSystem();
        $systemProfile->entry_point = 'https://actionnetwork.org/api/v2/';
        $systemProfile->api_token = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'apiToken');
        $client = new Jsor\HalClient\HalClient(
            'https://actionnetwork.org/api/v2/', new CRM_OSDI_FixtureHttpClient()
        );
        //$client = new Jsor\HalClient\HalClient('https://actionnetwork.org/api/v2/');
        return new Civi\Osdi\ActionNetwork\RemoteSystem($systemProfile, $client);
    }

    private function createMapper(\Civi\Osdi\ActionNetwork\RemoteSystem $system)
    {
        return new Civi\Osdi\ActionNetwork\Mapper\Generic($system);
    }

    public function makeBlankOsdiPerson(): \Civi\Osdi\ActionNetwork\OsdiPerson
    {
        return new Civi\Osdi\ActionNetwork\OsdiPerson();
    }

    /**
     * @return \Civi\Osdi\ActionNetwork\OsdiPerson
     * @throws \Civi\Osdi\Exception\InvalidArgumentException
     */
    private function savedOsdiPersonWithAllMappableFields(): \Civi\Osdi\ActionNetwork\OsdiPerson
    {
        $unsavedNewPerson = $this->makeBlankOsdiPerson();
        $unsavedNewPerson->set('given_name', 'Testy');
        $unsavedNewPerson->set('family_name', 'McTest');
        $unsavedNewPerson->set('email_addresses', [['address' => 'testy@test.net']]);
        $unsavedNewPerson->set('phone_numbers', [['number' => '12023334444']]);
        $unsavedNewPerson->set('postal_addresses', [[
            'address_lines' => ['123 Test St.'],
            'locality' => 'Testville',
            'region' => 'TN',
            'postal_code' => '12345',
            'country' => 'US'
        ]]);
        $unsavedNewPerson->set('languages_spoken', ['es']);
        return $this->system->savePerson($unsavedNewPerson);
    }

    public function testMapLocalContactOntoExistingRemotePerson_ChangeName() {
        $this->markTestSkipped();
        $civiApiParams = ['values' => ['first_name' => 'DifferentFirst', 'last_name' => 'DifferentLast']];
        $contact = civicrm_api4('Contact', 'create', $civiApiParams);
        $existingRemotePerson = $this->savedOsdiPersonWithAllMappableFields();
        $result = $this->mapper->mapContactOntoRemotePerson($contact->single()['id'], $existingRemotePerson);
        $this->assertEquals('DifferentFirst', $result->getAltered('given_name'));
    }
}