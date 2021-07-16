<?php /** @noinspection PhpFullyQualifiedNameUsageInspection */

use CRM_OSDI_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test \Civi\Osdi\ActionNetwork\RemoteSystem
 *
 * @group headless
 */
class CRM_OSDI_ActionNetwork_RemoteSystemTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    CRM_OSDI_FixtureHttpClient::resetHistory();
    parent::setUp();
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  public function createRemoteSystem(): \Civi\Osdi\ActionNetwork\RemoteSystem {
    $systemProfile = new CRM_OSDI_BAO_SyncProfile();
    $systemProfile->entry_point = 'https://actionnetwork.org/api/v2/';
    $systemProfile->api_token = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'apiToken');
    $client = new Jsor\HalClient\HalClient(
        'https://actionnetwork.org/api/v2/',
        new CRM_OSDI_FixtureHttpClient());
    //$client = new Jsor\HalClient\HalClient('https://actionnetwork.org/api/v2/');
    return new Civi\Osdi\ActionNetwork\RemoteSystem($systemProfile, $client);
  }

  public function makeBlankOsdiPerson(): \Civi\Osdi\ActionNetwork\OsdiPerson {
    return new Civi\Osdi\ActionNetwork\OsdiPerson();
  }

  /**
   * @return \Civi\Osdi\ActionNetwork\OsdiPerson
   * @throws \Civi\Osdi\Exception\InvalidArgumentException
   */
  private function makeNewOsdiPersonWithFirstLastEmail(): \Civi\Osdi\ActionNetwork\OsdiPerson {
    $unsavedNewPerson = $this->makeBlankOsdiPerson();
    $unsavedNewPerson->set('given_name', 'Testy');
    $unsavedNewPerson->set('family_name', 'McTest');
    $unsavedNewPerson->set('email_addresses', [['address' => 'testy@test.net']]);
    return $unsavedNewPerson;
  }

  public function expected($key): string {
    $expected = [];
    if (array_key_exists($key, $expected)) return $expected[$key];
    $remotePersonTest = new CRM_OSDI_ActionNetwork_OsdiPersonTest();
    return $remotePersonTest->expected($key);
  }

  public function testPersonCreate_Fetch() {
    $system = $this->createRemoteSystem();

    // CREATE
    $unsavedNewPerson = $this->makeNewOsdiPersonWithFirstLastEmail();
    $this->assertNull($unsavedNewPerson->getId());
    $savedPerson = $system->save($unsavedNewPerson);
    $savedPersonId = $savedPerson->getId();
    $this->assertNotNull($savedPersonId);

    // READ
    $fetchedOsdiPerson = $system->fetchPersonById($savedPersonId);
    $fetchedEmailAddresses = $fetchedOsdiPerson->getOriginal('email_addresses');
    $this->assertEquals('testy@test.net', $fetchedEmailAddresses[0]['address']);
  }

  public function testPersonCreate_Set() {
    $system = $this->createRemoteSystem();

    // CREATE
    $unsavedNewPerson = $this->makeNewOsdiPersonWithFirstLastEmail();
    $savedPerson = $system->savePerson($unsavedNewPerson);

    // UPDATE (SET)
    $savedPerson->set('family_name', 'Testerson');
    $reSavedOsdiPerson = $system->savePerson($savedPerson);
    $this->assertEquals(
        'Testerson', $reSavedOsdiPerson->getOriginal('family_name'));

    // clean up
    $system->savePerson($unsavedNewPerson);
  }

  public function testPersonCreate_Append() {
    $system = $this->createRemoteSystem();

    // CREATE
    $unsavedNewPerson = $this->makeNewOsdiPersonWithFirstLastEmail();
    $savedPerson = $system->savePerson($unsavedNewPerson);

    // UPDATE (APPEND)
    $savedPerson->appendTo('identifiers', 'donuts:yumyumyum');
    $reSavedOsdiPerson = $system->savePerson($savedPerson);
    $this->assertContains(
        'donuts:yumyumyum',
        $reSavedOsdiPerson->getOriginal('identifiers'));

    // TRY TO APPEND TO A NON-APPENDABLE FIELD
    $this->expectException(\Civi\Osdi\Exception\InvalidArgumentException::class);
    $this->expectExceptionMessage('Cannot append value to single-value field: "email_addresses"');
    $savedPerson->appendTo('email_addresses', [['address' => 'second@te.st']]);
  }

  public function testPersonCreate_FindByEmail() {
    $system = $this->createRemoteSystem();

    // CREATE
    $unsavedNewPerson = $this->makeBlankOsdiPerson();
    $unsavedNewPerson->set('email_addresses', [['address' => 'testy@test.net']]);
    $savedPerson = $system->savePerson($unsavedNewPerson);
    $savedPersonId = $savedPerson->getId();

    // FIND
    $searchResults = $system->find('osdi:people', [['email', 'eq', 'testy@test.net']]);
    $resultIds = array_map(
        function (\Civi\Osdi\ActionNetwork\OsdiPerson $foundPerson) { return $foundPerson->getId(); },
        $searchResults->toArray());
    $this->assertContains($savedPersonId, $resultIds);
  }

  public function testPersonFindByExactStringReturnsExactMatches() {
    $system = $this->createRemoteSystem();

    // CREATE
    $unsavedNewPerson = $this->makeNewOsdiPersonWithFirstLastEmail();
    $savedPerson = $system->savePerson($unsavedNewPerson);
    $familyName = $savedPerson->getOriginal('family_name');
    $abbreviatedFamilyName = substr($familyName, 0, 4);
    $this->assertNotEquals($abbreviatedFamilyName, $familyName);

    // FIND
    $searchResults = $system->find('osdi:people', [['family_name', 'eq', $familyName]]);
    /** @var \Civi\Osdi\ActionNetwork\OsdiPerson $foundPerson */
    foreach ($searchResults->toArray() as $foundPerson) {
      $this->assertEquals($familyName, $foundPerson->getOriginal('family_name'));
    }

    $searchResults = $system->find('osdi:people', [['family_name', 'eq', $abbreviatedFamilyName]]);
    foreach ($searchResults->toArray() as $foundPerson) {
      $this->assertEquals($abbreviatedFamilyName, $foundPerson->getOriginal('family_name'));
    }
  }

  public function testPersonCreate_FindByFirstAndLast() {
    $system = $this->createRemoteSystem();

    // CREATE
    $unsavedNewPerson = $this->makeNewOsdiPersonWithFirstLastEmail();
    $savedPerson = $system->savePerson($unsavedNewPerson);
    $savedPersonId = $savedPerson->getId();
    $givenName = $savedPerson->getOriginal('given_name');
    $familyName = $savedPerson->getOriginal('family_name');

    // FIND
    $searchResults = $system->find('osdi:people',
        [
            ['given_name', 'eq', $givenName],
            ['family_name', 'eq', $familyName]
        ]);
    $resultIds = array_map(
        function (\Civi\Osdi\ActionNetwork\OsdiPerson $foundPerson) { return $foundPerson->getId(); },
        $searchResults->toArray());
    $this->assertContains($savedPersonId, $resultIds);
  }

  public function testPersonCreate_FindByDateModified() {
    $system = $this->createRemoteSystem();

    // CREATE
    $unsavedNewPerson1 = $this->makeBlankOsdiPerson();
    $unsavedNewPerson1->set('email_addresses', [['address' => 'first@test.net']]);
    $unsavedNewPerson2 = $this->makeBlankOsdiPerson();
    $unsavedNewPerson2->set('email_addresses', [['address' => 'second@test.net']]);
    $unsavedNewPerson3 = $this->makeBlankOsdiPerson();
    $unsavedNewPerson3->set('email_addresses', [['address' => 'third@test.net']]);

    $savedPerson1 = $system->savePerson($unsavedNewPerson1);
    $savedPerson1ModTime = $savedPerson1->getOriginal('modified_date');
    if (time() - strtotime($savedPerson1ModTime) < 2) sleep(1);

    $savedPerson2 = $system->savePerson($unsavedNewPerson2);
    $savedPerson2ModTime = $savedPerson2->getOriginal('modified_date');
    if (time() - strtotime($savedPerson2ModTime < 2)) sleep(1);

    $savedPerson3 = $system->savePerson($unsavedNewPerson3);
    $savedPerson3ModTime = $savedPerson3->getOriginal('modified_date');

    // FIND
    $searchResults = $system->find('osdi:people', [['modified_date', 'lt', $savedPerson2ModTime]]);
    /** @var \Civi\Osdi\ActionNetwork\OsdiPerson $foundPerson */
    foreach ($searchResults->toArray() as $foundPerson) {
      $this->assertLessThan(
          strtotime($savedPerson2ModTime), 
          strtotime($foundPerson->getOriginal('modified_date')));
      $resultIds[] = $foundPerson->getId();
    }
    $this->assertContains($savedPerson1->getId(), $resultIds);

    $searchResults = $system->find('osdi:people', [['modified_date', 'gt', $savedPerson2ModTime]]);
    foreach ($searchResults->toArray() as $foundPerson) {
      $this->assertGreaterThan(
          strtotime($savedPerson2ModTime),
          strtotime($foundPerson->getOriginal('modified_date')));
      $resultIds[] = $foundPerson->getId();
    }
    $this->assertContains($savedPerson3->getId(), $resultIds);

    $searchResults = $system->find('osdi:people', [
        ['modified_date', 'gt', $savedPerson1ModTime],
        ['modified_date', 'lt', $savedPerson3ModTime],
    ]);
    foreach ($searchResults->toArray() as $foundPerson) {
      $resultIds[] = $foundPerson->getId();
    }
    $this->assertContains($savedPerson2->getId(), $resultIds);
  }

  public function testFactoryMake_Tag() {
    $system = $this->createRemoteSystem();
    $osdiTag = $system->makeOsdiObject('osdi:tags');
    $osdiTag->set('name', 'test');
    $this->assertEquals('test', $osdiTag->getAltered('name'));
  }

  public function testTagCreate_Fetch() {
    $system = $this->createRemoteSystem();

    // CREATE
    $unsavedTag = $system->makeOsdiObject('osdi:tags');
    $unsavedTag->set('name', 'Tagalina');
    $this->assertNull($unsavedTag->getId());
    $savedTag = $system->save($unsavedTag);
    $savedTagId = $savedTag->getId();
    $this->assertNotNull($savedTagId);

    // READ
    $fetchedOsdiTag = $system->fetchById('osdi:tags', $savedTagId);
    $this->assertEquals('Tagalina', $fetchedOsdiTag->getOriginal('name'));
  }

  public function testTaggingCreate_FetchComponents() {
    $system = $this->createRemoteSystem();

    // CREATE
    $unsavedTag = $system->makeOsdiObject('osdi:tags');
    $unsavedTag->set('name', 'Tagalina');
    $savedTag = $system->save($unsavedTag);

    $unsavedPerson = $this->makeNewOsdiPersonWithFirstLastEmail();
    /** @var \Civi\Osdi\ActionNetwork\OsdiPerson $savedPerson */
    $savedPerson = $system->save($unsavedPerson);

    /** @var \Civi\Osdi\ActionNetwork\OsdiTagging $unsavedTagging */
    $unsavedTagging = $system->makeOsdiObject('osdi:taggings');
    $unsavedTagging->setTag($savedTag, $system);
    $unsavedTagging->setPerson($savedPerson, $system);
    $savedTagging = $system->save($unsavedTagging);

    // FETCH COMPONENTS

    $this->assertEquals($savedTag->getId(), $savedTagging->getTag()->getId());
    $this->assertEquals($savedPerson->getId(), $savedTagging->getPerson()->getId());
  }

  public function testTaggingCreate_Delete() {
    $system = $this->createRemoteSystem();

    // CREATE
    $unsavedTag = $system->makeOsdiObject('osdi:tags');
    $unsavedTag->set('name', 'Tagalina');
    $savedTag = $system->save($unsavedTag);

    $unsavedPerson = $this->makeNewOsdiPersonWithFirstLastEmail();
    /** @var \Civi\Osdi\ActionNetwork\OsdiPerson $savedPerson */
    $savedPerson = $system->save($unsavedPerson);

    /** @var \Civi\Osdi\ActionNetwork\OsdiTagging $unsavedTagging */
    $unsavedTagging = $system->makeOsdiObject('osdi:taggings');
    $unsavedTagging->setTag($savedTag, $system);
    $unsavedTagging->setPerson($savedPerson, $system);
    $savedTagging = $system->save($unsavedTagging);
    $savedTaggingUrl = $savedTagging->getOwnUrl($system);
    $this->assertStringStartsWith('http', $savedTaggingUrl);

    // DELETE
    $system->delete($savedTagging);
    $this->expectException(\Civi\Osdi\Exception\EmptyResultException::class);
    $system->fetchObjectByUrl('osdi:taggings', $savedTaggingUrl);
  }

}
