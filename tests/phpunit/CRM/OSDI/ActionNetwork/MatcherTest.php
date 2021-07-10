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
class CRM_OSDI_ActionNetwork_MatcherTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface,
                                                                                        HookInterface,
                                                                                        TransactionalInterface
{
    /**
     * @var \Civi\Osdi\ActionNetwork\RemoteSystem
     */
    private $system;

    /**
     * @var \Civi\Osdi\ActionNetwork\Matcher\OneToOneEmailOrFirstLastEmail
     */
    private $matcher;

    public function setUpHeadless(): \Civi\Test\CiviEnvBuilder
    {
        // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
        // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
        return \Civi\Test::headless()->installMe(__DIR__)->apply();
    }

    public function setUp(): void
    {
        $this->system = $this->createRemoteSystem();
        $this->matcher = $this->createMatcher($this->system);
        CRM_OSDI_FixtureHttpClient::resetHistory();
        parent::setUp();
    }

    public function tearDown(): void
    {
        parent::tearDown();
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

    public function makeBlankOsdiPerson(): \Civi\Osdi\ActionNetwork\OsdiPerson
    {
        return new Civi\Osdi\ActionNetwork\OsdiPerson();
    }

    /**
     * @return \Civi\Osdi\ActionNetwork\OsdiPerson
     * @throws \Civi\Osdi\Exception\InvalidArgumentException
     */
    private function makeNewOsdiPersonWithFirstLastEmail(): \Civi\Osdi\ActionNetwork\OsdiPerson
    {
        $unsavedNewPerson = $this->makeBlankOsdiPerson();
        $unsavedNewPerson->set('given_name', 'Testy');
        $unsavedNewPerson->set('family_name', 'McTest');
        $unsavedNewPerson->set('email_addresses', [['address' => 'testy@test.net']]);
        return $unsavedNewPerson;
    }

    private function createMatcher(\Civi\Osdi\RemoteSystemInterface $system
    ): \Civi\Osdi\ActionNetwork\Matcher\OneToOneEmailOrFirstLastEmail {
        return new Civi\Osdi\ActionNetwork\Matcher\OneToOneEmailOrFirstLastEmail($system);
    }

    private function civiApi4CreateContact(
        string $firstName,
        string $lastName,
        string $emailAddress = null
    ): \Civi\Api4\Generic\Result {
        $apiCreateParams = [
            'values' => [
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]
        ];
        if (!is_null($emailAddress)) {
            $apiCreateParams['chain'] = [
                'email' => [
                    'Email',
                    'create',
                    [
                        'values' => [
                            'contact_id' => '$id',
                            'email' => $emailAddress
                        ]
                    ]
                ],
            ];
        }
        return civicrm_api4('Contact', 'create', $apiCreateParams);
    }

    /**
     * @param $address
     * @return \Civi\Api4\Generic\Result
     * @throws API_Exception
     * @throws \Civi\API\Exception\NotImplementedException
     */
    private function civiApi4GetContactByEmail($address): \Civi\Api4\Generic\Result
    {
        return civicrm_api4(
            'Contact',
            'get',
            [
                'select' => [
                    'row_count',
                ],
                'join' => [
                    ['Email AS email', true],
                ],
                'where' => [
                    ['email.email', '=', $address],
                    ['email.is_primary', '=', true],
                    ['is_deleted', '=', false],
                ],
                'checkPermissions' => false,
            ]
        );
    }

    private function civiApi4GetSingleContactById($id): array
    {
        return civicrm_api4(
            'Contact',
            'get',
            [
                'select' => [
                    'id',
                    'first_name',
                    'last_name',
                    'email.email'
                ],
                'join' => [
                    ['Email AS email', true],
                ],
                'where' => [
                    ['id', '=', $id],
                    ['email.is_primary', '=', true],
                    ['is_deleted', '=', false],
                ],
                'checkPermissions' => false,
            ]
        )->single();
    }

    private function setUpExactlyOneMatchByEmail_DifferentNames(): array
    {
        $unsavedRemotePerson = $this->makeNewOsdiPersonWithFirstLastEmail();
        $savedRemotePerson = $this->system->savePerson($unsavedRemotePerson);
        $emailAddress = $savedRemotePerson->getEmailAddress();
        $this->assertNotEmpty($emailAddress);
        $contactId = $this->civiApi4CreateContact('Fizz', 'Bang', $emailAddress)->first()['id'];
        $ContactsWithTheEmailAddress = $this->civiApi4GetContactByEmail($emailAddress);
        $this->assertEquals(1, $ContactsWithTheEmailAddress->count());
        return array($emailAddress, $savedRemotePerson, $contactId);
    }

    private function setUpLocalAndRemotePeople_SameName_DifferentEmail()
    {
        $unsavedRemotePerson = $this->makeNewOsdiPersonWithFirstLastEmail();
        $savedRemotePerson = $this->system->savePerson($unsavedRemotePerson);
        $emailAddress = $savedRemotePerson->getEmailAddress();

        $differentEmailAddress = "fuzzityfizz.$emailAddress";
        $contactId = $this->civiApi4CreateContact(
            $savedRemotePerson->get('given_name'),
            $savedRemotePerson->get('family_name'),
            $differentEmailAddress
            )->first()['id'];
        return [$contactId, $savedRemotePerson];
    }

    private function setUpRemotePerson_TwoLocalContactsMatchingByEmail_OneAlsoMatchingByName(): array
    {
        $unsavedRemotePerson = $this->makeNewOsdiPersonWithFirstLastEmail();
        $savedRemotePerson = $this->system->savePerson($unsavedRemotePerson);

        $emailAddress = $savedRemotePerson->getEmailAddress();
        $this->assertNotEmpty($emailAddress);

        $firstName = $savedRemotePerson->get('given_name');
        $lastName = $savedRemotePerson->get('family_name');
        $this->assertNotEquals($firstName, 'foo');

        $idOfMatchingContact = $this->civiApi4CreateContact(
            $firstName,
            $lastName,
            $emailAddress
        )->first()['id'];
        $idOf_Non_MatchingContact = $this->civiApi4CreateContact(
            'foo',
            'foo',
            $emailAddress
        )->first()['id'];

        $civiContactsWithSameEmail = $this->civiApi4GetContactByEmail($emailAddress);
        $this->assertGreaterThan(1, $civiContactsWithSameEmail->count());
        return [$savedRemotePerson, $idOfMatchingContact, $idOf_Non_MatchingContact];
    }

    private function setUpRemotePerson_TwoLocalContactsMatchingByEmail_NeitherMatchingByName(): array {
        $unsavedRemotePerson = $this->makeNewOsdiPersonWithFirstLastEmail();
        $savedRemotePerson = $this->system->savePerson($unsavedRemotePerson);

        $emailAddress = $savedRemotePerson->getEmailAddress();
        $this->assertNotEmpty($emailAddress);

        $firstName = $savedRemotePerson->get('given_name');
        $this->assertNotEquals($firstName, 'foo');
        $this->assertNotEquals($firstName, 'bar');

        $idsOfContactsWithSameEmailAndDifferentName[] = $this->civiApi4CreateContact(
            'foo',
            'foo',
            $emailAddress
        )->first()['id'];
        $idsOfContactsWithSameEmailAndDifferentName[] = $this->civiApi4CreateContact(
            'bar',
            'bar',
            $emailAddress
        )->first()['id'];
        return [$savedRemotePerson, $idsOfContactsWithSameEmailAndDifferentName];
    }

    private function assertMatchResultIsNotError_NoMatch_ZeroCount(\Civi\Osdi\MatchResult $matchResult): void
    {
        $this->assertEquals(0, $matchResult->count());
        $this->assertFalse($matchResult->isError());
        $this->assertEquals(\Civi\Osdi\MatchResult::NO_MATCH, $matchResult->status());
    }

    public function testRemoteMatch_OneToOneEmailSuccess()
    {
        list($emailAddress, $remotePerson, $contactId) = $this->setUpExactlyOneMatchByEmail_DifferentNames();
        $matchResult = $this->matcher->findRemoteMatchForLocalContact($contactId);
        $this->assertEquals(1, $matchResult->count());
        $this->assertEquals($emailAddress, $matchResult->matches()[0]->getEmailAddress());
    }

    public function testRemoteMatch_NoMatchingEmail()
    {
        list($contactId, $remotePerson) = $this->setUpLocalAndRemotePeople_SameName_DifferentEmail();

        $matchResult = $this->matcher->findRemoteMatchForLocalContact($contactId);
        $this->assertMatchResultIsNotError_NoMatch_ZeroCount($matchResult);
    }

    public function testRemoteMatch_BadContactId()
    {
        $contactId = 999999999;
        $contactsWithTheId = civicrm_api4(
            'Contact',
            'get',
            [
                'select' => ['row_count'],
                'where' => [['id', '=', $contactId]]
            ]
        );
        $this->assertEquals(0, $contactsWithTheId->count());

        $matchResult = $this->matcher->findRemoteMatchForLocalContact($contactId);
        $this->assertEquals(0, $matchResult->count());
        $this->assertTrue($matchResult->isError());
        $this->assertEquals(\Civi\Osdi\MatchResult::ERROR_INVALID_ID, $matchResult->status());
    }

    public function testRemoteMatch_NoEmail()
    {
        $unsavedRemotePerson = $this->makeNewOsdiPersonWithFirstLastEmail();
        $this->system->savePerson($unsavedRemotePerson);
        $contactArr = $this->civiApi4CreateContact('Testy', 'McTest')->first();
        $contactId = $contactArr['id'];

        $matchResult = $this->matcher->findRemoteMatchForLocalContact($contactId);
        $this->assertMatchResultIsNotError_NoMatch_ZeroCount($matchResult);
    }

    public function testRemoteMatch_EmailIndeterminate_FirstLastSuccess()
    {
        $a = $this->setUpRemotePerson_TwoLocalContactsMatchingByEmail_OneAlsoMatchingByName();
        list($remotePerson, $idOfMatchingContact, $idOf_Non_MatchingContact) = $a;
        $matchingContact = $this->civiApi4GetSingleContactById($idOfMatchingContact);

        $matchResult1 = $this->matcher->findRemoteMatchForLocalContact($idOfMatchingContact);
        $this->assertEquals(1, $matchResult1->count());
        $this->assertEquals($matchingContact['email.email'],
                            $matchResult1->first()->getEmailAddress());
        $this->assertEquals($matchingContact['first_name'],
                            $matchResult1->first()->get('given_name'));
        $this->assertEquals($matchingContact['last_name'],
                            $matchResult1->first()->get('family_name'));

        $matchResult2 = $this->matcher->findRemoteMatchForLocalContact($idOf_Non_MatchingContact);
        $this->assertMatchResultIsNotError_NoMatch_ZeroCount($matchResult2);
    }

    public function testRemoteMatch_EmailIndeterminate_NoMatchingFirstLast()
    {
        $a = $this->setUpRemotePerson_TwoLocalContactsMatchingByEmail_NeitherMatchingByName();
        list($remotePerson, $idsOfContactsWithSameEmailAndDifferentName) = $a;

        foreach ($idsOfContactsWithSameEmailAndDifferentName as $id) {
            $matchResult = $this->matcher->findRemoteMatchForLocalContact($id);
            $this->assertMatchResultIsNotError_NoMatch_ZeroCount($matchResult);
        }
    }

    public function testLocalMatch_OneToOneEmailSuccess()
    {
        list($emailAddress, $remotePerson, $contactId) = $this->setUpExactlyOneMatchByEmail_DifferentNames();
        $matchResult = $this->matcher->findLocalMatchForRemotePerson($remotePerson);
        $this->assertEquals(1, $matchResult->count());
        $this->assertEquals($emailAddress, $matchResult->matches()[0]['email.email']);
    }

    public function testLocalMatch_NoMatchingEmail()
    {
        list($contactId, $remotePerson) = $this->setUpLocalAndRemotePeople_SameName_DifferentEmail();
        $matchResult = $this->matcher->findLocalMatchForRemotePerson($remotePerson);
        $this->assertMatchResultIsNotError_NoMatch_ZeroCount($matchResult);
    }

    public function testLocalMatch_EmailIndeterminate_FirstLastSuccess()
    {
        $a = $this->setUpRemotePerson_TwoLocalContactsMatchingByEmail_OneAlsoMatchingByName();
        list($remotePerson, $idOfMatchingContact, $idOf_Non_MatchingContact) = $a;

        $matchResult = $this->matcher->findLocalMatchForRemotePerson($remotePerson);
        $this->assertEquals(1, $matchResult->count());
        $this->assertEquals($idOfMatchingContact,
                            $matchResult->first()['id']);
        $this->assertEquals($remotePerson->getEmailAddress(),
                            $matchResult->first()['email.email']);
        $this->assertEquals($remotePerson->get('given_name'),
                            $matchResult->first()['first_name']);
        $this->assertEquals($remotePerson->get('family_name'),
                            $matchResult->first()['last_name']);
    }

    public function testLocalMatch_EmailIndeterminate_NoMatchingFirstLast()
    {
        $a = $this->setUpRemotePerson_TwoLocalContactsMatchingByEmail_NeitherMatchingByName();
        list($remotePerson, $idsOfContactsWithSameEmailAndDifferentName) = $a;

        $matchResult = $this->matcher->findLocalMatchForRemotePerson($remotePerson);
        $this->assertMatchResultIsNotError_NoMatch_ZeroCount($matchResult);
    }
}
