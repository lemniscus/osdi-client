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
     * @var \Civi\Osdi\ActionNetwork\Mapper\Example
     */
    private $mapper;

    public function setUpHeadless(): \Civi\Test\CiviEnvBuilder
    {
        return \Civi\Test::headless()->installMe(__DIR__)->apply();
    }

    public function setUp(): void
    {
        $this->system = $this->createRemoteSystem();
        $this->mapper = $this->createMapper($this->system);
        CRM_OSDI_FixtureHttpClient::resetHistory();
        parent::setUp();
    }

    public function tearDown(): void
    {
        $reset = $this->getCookieCutterOsdiPerson();
        parent::tearDown();
    }

    public function createRemoteSystem(): \Civi\Osdi\ActionNetwork\RemoteSystem
    {
        $systemProfile = new CRM_OSDI_BAO_RemoteSystem();
        $systemProfile->entry_point = 'https://actionnetwork.org/api/v2/';
        $systemProfile->api_token = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'apiToken');
        $client = new Jsor\HalClient\HalClient(
            'https://actionnetwork.org/api/v2/'
            , new CRM_OSDI_FixtureHttpClient()
        );
        return new Civi\Osdi\ActionNetwork\RemoteSystem($systemProfile, $client);
    }

    private function createMapper(\Civi\Osdi\ActionNetwork\RemoteSystem $system)
    {
        return new Civi\Osdi\ActionNetwork\Mapper\Example($system);
    }

    public function makeBlankOsdiPerson(): \Civi\Osdi\ActionNetwork\OsdiPerson
    {
        return new Civi\Osdi\ActionNetwork\OsdiPerson();
    }

    /**
     * @return \Civi\Osdi\ActionNetwork\OsdiPerson
     * @throws \Civi\Osdi\Exception\InvalidArgumentException
     */
    private function getCookieCutterOsdiPerson(): \Civi\Osdi\ActionNetwork\OsdiPerson
    {
        $unsavedNewPerson = $this->makeBlankOsdiPerson();
        $unsavedNewPerson->set('given_name', 'Cookie');
        $unsavedNewPerson->set('family_name', 'Cutter');
        $unsavedNewPerson->set('email_addresses', [['address' => 'cookie@yum.net']]);
        $unsavedNewPerson->set('phone_numbers', [['number' => '12023334444']]);
        $unsavedNewPerson->set('postal_addresses', [[
            'address_lines' => ['202 N Main St'],
            'locality' => 'Licking',
            'region' => 'MO',
            'postal_code' => '65542',
            'country' => 'US'
        ]]);
        $unsavedNewPerson->set('languages_spoken', ['es']);
        return $this->system->savePerson($unsavedNewPerson);
    }

    private function getCookieCutterCiviContact(): array
    {
        $createContact = Civi\Api4\Contact::create()->setValues(
            [
                'first_name' => 'Cookie',
                'last_name' => 'Cutter',
                'preferred_language:name' => 'es_MX'
            ]
        )->addChain('email', \Civi\Api4\Email::create()
            ->setValues(
                [
                    'contact_id' => '$id',
                    'email' => 'cookie@yum.net'
                ]
            )
        )->addChain('phone', \Civi\Api4\Phone::create()
            ->setValues(
                [
                    'contact_id' => '$id',
                    'phone' => '12023334444',
                    'phone_type_id:name' => 'Mobile'
                ]
            )
        )->addChain('address', \Civi\Api4\Address::create()
            ->setValues(
                [
                    'contact_id' => '$id',
                    'street_address' => '123 Test St',
                    'city' => 'Licking',
                    'state_province_id:name' => 'Missouri',
                    'postal_code' => 65542,
                    'country_id:name' => 'US'
                ]
            )
        )->execute();
        $cid = $createContact->single()['id'];
        return Civi\Api4\Contact::get(0)
            ->addWhere('id', '=', $cid)
            ->addJoin('Address')->addJoin('Email')->addJoin('Phone')
            ->addSelect('*', 'address.*', 'address.state_province_id:name', 'address.country_id:name', 'email.*', 'phone.*')
            ->execute()
            ->single();
    }


    /*
     * LOCAL ===> REMOTE
     */

    public function testMapLocalContactToNewRemotePerson()
    {
        $civiContact = $this->getCookieCutterCiviContact();
        $this->assertEquals('Missouri', $civiContact['address.state_province_id:name']);
        $stateAbbreviation = 'MO';

        $result = $this->mapper->mapContactOntoRemotePerson($civiContact['id']);
        $this->assertEquals('Civi\Osdi\ActionNetwork\OsdiPerson', get_class($result));
        $this->assertEquals($civiContact['first_name'], $result->get('given_name'));
        $this->assertEquals($civiContact['last_name'], $result->get('family_name'));
        $this->assertEquals($civiContact['address.street_address'], $result->get('postal_addresses')[0]['address_lines'][0]);
        $this->assertEquals($civiContact['address.city'], $result->get('postal_addresses')[0]['locality']);
        $this->assertEquals($stateAbbreviation, $result->get('postal_addresses')[0]['region']);
        $this->assertEquals($civiContact['address.postal_code'], $result->get('postal_addresses')[0]['postal_code']);
        $this->assertEquals($civiContact['address.country_id:name'], $result->get('postal_addresses')[0]['country']);
        $this->assertEquals($civiContact['email.email'], $result->get('email_addresses')[0]['address']);
        $this->assertEquals($civiContact['phone.phone_numeric'], $result->get('phone_numbers')[0]['number']);
        $this->assertEquals(substr($civiContact['preferred_language'], 0, 2), $result->get('languages_spoken')[0]);
    }

    public function testMapLocalContactOntoExistingRemotePerson_ChangeName()
    {
        $existingRemotePerson = $this->getCookieCutterOsdiPerson();
        $civiContact = $this->getCookieCutterCiviContact();
        Civi\Api4\Contact::update(0)
            ->addWhere('id', '=', $civiContact['id'])
            ->setValues(['first_name' => 'DifferentFirst', 'last_name' => 'DifferentLast'])
            ->execute();

        $result = $this->mapper->mapContactOntoRemotePerson(
            $civiContact['id'],
            $existingRemotePerson
        );
        $this->assertEquals('Civi\Osdi\ActionNetwork\OsdiPerson', get_class($result));
        $this->assertEquals('DifferentFirst', $result->get('given_name'));
        $this->assertEquals('DifferentLast', $result->get('family_name'));
        $this->assertEquals($civiContact['email.email'], $result->get('email_addresses')[0]['address']);
    }

    public function testMapLocalContactOntoExistingRemotePerson_ChangePhone()
    {
        $existingRemotePerson = $this->getCookieCutterOsdiPerson();
        $civiContact = $this->getCookieCutterCiviContact();
        Civi\Api4\Phone::update(0)
            ->addWhere('id', '=', $civiContact['phone.id'])
            ->addValue('phone', '19098887777')
            ->execute();

        $result = $this->mapper->mapContactOntoRemotePerson(
            $civiContact['id'],
            $existingRemotePerson
        );
        $this->assertEquals('Civi\Osdi\ActionNetwork\OsdiPerson', get_class($result));
        $this->assertEquals('19098887777', $result->get('phone_numbers')[0]['number']);
        $this->assertEquals($civiContact['first_name'], $result->get('given_name'));
        $this->assertEquals($civiContact['last_name'], $result->get('family_name'));
    }


    /*
     * REMOTE ===> LOCAL
     */

    public function testMapRemotePersonToNewLocalContact()
    {
        $remotePerson = $this->getCookieCutterOsdiPerson();
        $this->assertEquals('MO', $remotePerson->get('postal_addresses')[0]['region']);
        $stateName = 'Missouri';

        $result = $this->mapper->mapRemotePersonOntoContact($remotePerson);
        $this->assertEquals('Civi\Api4\Generic\DAOCreateAction', get_class($result));
        $cid = $result->execute()->single()['id'];
        $resultContact = Civi\Api4\Contact::get(0)
            ->addWhere('id', '=', $cid)
            ->addJoin('Address')->addJoin('Email')->addJoin('Phone')
            ->addSelect('*', 'address.*', 'address.state_province_id:name', 'address.country_id:name', 'email.*', 'phone.*')
            ->execute()
            ->single();
        $this->assertEquals($remotePerson->get('given_name'), $resultContact['first_name']);
        $this->assertEquals($remotePerson->get('family_name'), $resultContact['last_name']);
        $this->assertEquals($remotePerson->get('postal_addresses')[0]['address_lines'][0], $resultContact['address.street_address']);
        $this->assertEquals($remotePerson->get('postal_addresses')[0]['locality'], $resultContact['address.city']);
        $this->assertEquals($stateName, $resultContact['address.state_province_id:name']);
        $this->assertEquals($remotePerson->get('postal_addresses')[0]['postal_code'], $resultContact['address.postal_code']);
        $this->assertEquals($remotePerson->get('postal_addresses')[0]['country'], $resultContact['address.country_id:name']);
        $this->assertEquals($remotePerson->get('email_addresses')[0]['address'], $resultContact['email.email']);
        $this->assertEquals($remotePerson->get('phone_numbers')[0]['number'], $resultContact['phone.phone_numeric']);
        $this->assertEquals($remotePerson->get('languages_spoken')[0], substr($resultContact['preferred_language'], 0, 2));
    }

    public function testMapRemotePersonOntoExistingLocalContact_ChangeName()
    {
        $existingLocalContactId = $this->getCookieCutterCiviContact()['id'];
        $existingRemotePerson = $this->getCookieCutterOsdiPerson();
        $existingRemotePerson->set('given_name', 'DifferentFirst');
        $existingRemotePerson->set('family_name', 'DifferentLast');
        $alteredRemotePerson = $this->system->savePerson($existingRemotePerson);

        $result = $this->mapper->mapRemotePersonOntoContact(
            $alteredRemotePerson,
            $existingLocalContactId
        );
        $this->assertEquals('Civi\Api4\Generic\DAOUpdateAction', get_class($result));
        $this->assertEquals('DifferentFirst', $result->getValue('first_name'));
        $this->assertEquals('DifferentLast', $result->getValue('last_name'));
        $this->assertEquals(
            $existingRemotePerson->get('email_addresses')[0]['address'],
            $result->getChain()['email'][2]['values']['email']
        );
    }

    public function testMapRemotePersonOntoExistingLocalContact_ChangePhone()
    {
        $existingLocalContactId = $this->getCookieCutterCiviContact()['id'];
        $existingRemotePerson = $this->getCookieCutterOsdiPerson();
        $existingRemotePerson->set('phone_numbers', [['number' => '19098887777']]);
        $alteredRemotePerson = $this->system->savePerson($existingRemotePerson);

        $result = $this->mapper->mapRemotePersonOntoContact(
            $alteredRemotePerson,
            $existingLocalContactId
        );
        $this->assertEquals('19098887777', $result->getChain()['phone'][2]['values']['phone']);
        $this->assertEquals($existingRemotePerson->get('given_name'), $result->getValue('first_name'));
        $this->assertEquals($existingRemotePerson->get('family_name'), $result->getValue('last_name'));
    }
}