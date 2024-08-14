<?php

namespace Civi\Osdi\ActionNetwork\Matcher;

use Civi\Osdi\ActionNetwork\DonationHelperTrait;
use Civi\Osdi\RemoteObjectInterface;
use Civi\OsdiClient;
use OsdiClient\ActionNetwork\PersonMatchingFixture as PersonMatchFixture;
use OsdiClient\ActionNetwork\TestUtils;
use PHPUnit;

/**
 * @group headless
 */
class DonationBasicTest extends PHPUnit\Framework\TestCase implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\TransactionalInterface {

  use DonationHelperTrait;

  private static \Civi\Osdi\ActionNetwork\Matcher\DonationBasic $matcher;

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public static function setUpBeforeClass(): void {
    PersonMatchFixture::$personClass = \Civi\Osdi\ActionNetwork\Object\Person::class;

    self::$system = TestUtils::createRemoteSystem();
    $container = OsdiClient::container();
    $container->register('Matcher', 'Donation', DonationBasic::class);
    self::$matcher = $container->getSingle('Matcher', 'Donation');

    static::$testFundraisingPage = static::getDefaultFundraisingPage();
    static::$financialTypeId = static::getTestFinancialTypeId();
    static::setLocalTimeZone();
  }

  private static function makePair($input): \Civi\Osdi\LocalRemotePair {
    $pair = new \Civi\Osdi\LocalRemotePair();
    if (is_a($input, RemoteObjectInterface::class)) {
      $pair->setRemoteObject($input)->setOrigin($pair::ORIGIN_REMOTE);
    }
    else {
      $pair->setLocalObject($input)->setOrigin($pair::ORIGIN_LOCAL);
    }
    return $pair;
  }

  /**
   * @return array{0: \Civi\Osdi\LocalObject\DonationBasic, 1: \Civi\Osdi\ActionNetwork\Object\Donation}
   */
  private function makeSameDonationOnBothSides(): array {
    $personPair = $this->createInSyncPerson();
    $remotePerson = $personPair->getRemoteObject();
    $localPerson = $personPair->getLocalObject();

    $localSystem = OsdiClient::container()->getSingle('LocalSystem', 'Civi');
    $localDonationTime = '2020-03-04 05:06:07';
    $unixTime = $localSystem->convertFromLocalizedDateTimeString($localDonationTime)->format('U');
    $remoteDonationTime = static::$system::formatDateTime($unixTime);

    $remoteDonation = new \Civi\Osdi\ActionNetwork\Object\Donation(self::$system);
    $remoteDonation->setDonor($remotePerson);
    $recipients = [['display_name' => 'Test recipient financial type', 'amount' => '5.55']];
    $remoteDonation->recipients->set($recipients);
    $remoteDonation->createdDate->set($remoteDonationTime);
    $remoteDonation->setFundraisingPage(static::$testFundraisingPage);
    $remoteDonation->save();

    $localDonation = new \Civi\Osdi\LocalObject\DonationBasic();
    $localDonation->setPerson($localPerson);
    $localDonation->amount->set('5.55');
    $localDonation->receiveDate->set($localDonationTime);
    $localDonation->financialTypeId->set(static::$financialTypeId);
    $localDonation->save();

    return [$localDonation, $remoteDonation];
  }

  public function testRemoteMatch_Success_NoSavedMatches() {
    [$localDonation, $remoteDonation] = $this->makeSameDonationOnBothSides();

    self::assertNotNull($remoteDonation->getId());

    $pair = $this->makePair($localDonation);
    $matchFindResult = self::$matcher->tryToFindMatchFor($pair);

    self::assertFalse($matchFindResult->isError());
    self::assertTrue($matchFindResult->gotMatch());
    self::assertEquals($remoteDonation->getId(), $matchFindResult->getMatch()->getId());
  }

  public function testLocalMatch_Success_NoSavedMatches() {
    [$localDonation, $remoteDonation] = $this->makeSameDonationOnBothSides();

    self::assertNotNull($localDonation->getId());

    $pair = $this->makePair($remoteDonation);
    $matchFindResult = self::$matcher->tryToFindMatchFor($pair);

    self::assertFalse($matchFindResult->isError(),
      print_r($matchFindResult->toArray(), TRUE));
    self::assertTrue($matchFindResult->gotMatch());
    self::assertEquals($localDonation->getId(), $matchFindResult->getMatch()->getId());
  }

  public function testLocalMatch_Success_WithSavedMatches() {
    // could also be made part of preceding tests
    self::markTestIncomplete('Todo');
  }

}
