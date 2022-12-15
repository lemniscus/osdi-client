<?php
namespace Civi\Osdi\ActionNetwork\Mapper;

use Civi\Osdi\ActionNetwork\Object\Donation as RemoteDonation;
use Civi\Osdi\ActionNetwork\Object\Person as RemotePerson;
use Civi\Osdi\LocalObject\Donation as LocalDonation;
use Civi\Osdi\LocalObject\PersonBasic as LocalPerson;
use Civi\Osdi\LocalObjectInterface;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Logger;
use Civi\Osdi\MapperInterface;
use Civi\Osdi\Exception\CannotMapException;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\Result\Map as MapResult;
use Civi\Osdi\MatcherInterface;
use \Civi\Osdi\ActionNetwork\RemoteFindResult;

class DonationBasic implements MapperInterface {

  const FUNDRAISING_PAGE_NAME = 'CiviCRM';

  private RemoteSystemInterface $remoteSystem;

  private MatcherInterface $personMatcher;

  protected static RemoteFindResult $remoteFundraisingPages;

  protected static array $financialTypesMap;

  protected static array $paymentInstrumentsMap;

  public function __construct(
    RemoteSystemInterface $remoteSystem,
    MatcherInterface $personMatcher
  ) {
    $this->remoteSystem = $remoteSystem;
    $this->personMatcher = $personMatcher;
  }

  protected function getRemoteFundraisingPages() {
    if (empty(static::$remoteFundraisingPages)) {
      static::$remoteFundraisingPages = $this->remoteSystem->findAll('osdi:fundraising_pages');
    }
    return static::$remoteFundraisingPages;
  }

  public function mapOneWay(LocalRemotePair $pair): MapResult {
    $result = new MapResult();

    try {
      if ($pair->isOriginLocal()) {
        $this->mapLocalToRemote($pair->getLocalObject(), $pair->getRemoteObject());
      }
      else {
        $this->mapRemoteToLocal($pair->getRemoteObject(), $pair->getLocalObject());
      }
      $result->setStatusCode($result::SUCCESS);
    }
    catch (\Throwable $e) {
      $result->setStatusCode($result::ERROR);
    }

    $pair->getResultStack()->push($result);
    return $result;
  }


  public function mapLocalToRemote(
    LocalObjectInterface $localDonation,
    RemoteObjectInterface $remoteDonation = NULL
  ): RemoteDonation {

    /** @var LocalDonation $localDonation */
    $localDonation->loadOnce();
    /** @var RemoteDonation $remoteDonation */
    $remoteDonation = $remoteDonation ?? new RemoteDonation($this->remoteSystem);

    // Load the contact that the donation belongs to.
    // @todo factory? (I don't get why factory uses static calls)
    $localPerson = new LocalPerson($localDonation->contactId->get());
    // Why do we need this?
    $personPair = new LocalRemotePair($localPerson);
    $personPair->setOrigin(LocalRemotePair::ORIGIN_LOCAL);
    $personPair->setLocalClass(get_class($localPerson)); // @todo understand why I need to do this?
    $matchResult = $this->personMatcher->tryToFindMatchFor($personPair);
    if (!$matchResult->gotMatch()) {
      throw new CannotMapException("Cannot sync a donation whose Contact (ID {$localPerson->getId()}) has no RemotePerson match.");
    }
    /** @var RemotePerson */
    $remotePerson = $matchResult->getMatch();
    $remoteDonation->donorHref->set($remotePerson->getUrlForRead());

    // Simple mappings
    $remoteDonation->createdDate->set($localDonation->receiveDate->get());
    $remoteDonation->currency->set($localDonation->currency->get());
    $remoteDonation->recipients->set([['display_name' => $localDonation->financialTypeLabel->get(), 'amount' => $localDonation->amount->get()]]);

    // Recurrence
    if ($localDonation->contributionRecurId->get()) {
      $freq = $localDonation->contributionRecurFrequency->get();
      $period = [
        'year'  => 'Yearly',
        'month' => 'Monthly',
        'week'  => 'Weekly',
        'day'   => 'Daily',
      ][$freq] ?? NULL;
      if (!$period) {
        // @todo what exception to throw?
        throw new CannotMapException("Unsupported recurring period '$freq'");
      }
      $remoteDonation->recurrence->set(['recurring' => TRUE, 'period' => $period]);
    }
    else {
      $remoteDonation->recurrence->set(['recurring' => FALSE]);
    }

    // This data is ignored by AN so no point trying to set it.
    // $remoteDonation->payment = ['method' => 'EFT', 'reference_number' => 'test_payment_1'];

    // Fundraising page
    // @todo $remoteDonation->fundraisingPageHref 
    // This is a challenge: on the Civi side we have just a string;
    // on the remote side we have an object. And we can't look up the
    // object by name.
    $found = NULL;
    foreach ($this->getRemoteFundraisingPages() as $fundraisingPage) {
      if ($fundraisingPage->title->get() === static::FUNDRAISING_PAGE_NAME) {
        $found = $fundraisingPage;
        break;
      }
      // else print "Not: " .$fundraisingPage->title->get() . "\n";
    }
    if ($found) {
      $remoteDonation->setFundraisingPage($fundraisingPage);
    }
    else {
      throw new CannotMapException("Cannot map local donation: Failed to find remote fundraising page called " . json_encode(self::FUNDRAISING_PAGE_NAME));
    }

    // @todo $remoteDonation->referrerData 

    return $remoteDonation;
  }


  /**
   */
  public function mapRemoteToLocal(
    RemoteObjectInterface $remoteDonation,
    LocalObjectInterface $localDonation = NULL
  ): LocalDonation {

    /** @var LocalDonation $localDonation */
    $localDonation = $localDonation ?? new LocalDonation();

    // Load the person that the donation belongs to.
    /** @var RemoteDonation $remoteDonation */
    $remotePerson = $remoteDonation->getDonor();
    $personPair = new LocalRemotePair(NULL, $remotePerson);
    $personPair->setOrigin(LocalRemotePair::ORIGIN_REMOTE);
    $personPair->setLocalClass(LocalPerson::class);
    $matchResult = $this->personMatcher->tryToFindMatchFor($personPair); // xxx
    if (!$matchResult->gotMatch()) {
      throw new CannotMapException("Cannot sync a donation whose Person ({$remotePerson->getId()}) has no LocalPerson match.");
    }
    $localPerson = $matchResult->getMatch();
    $localDonation->contactId->set($localPerson->getId());

    // Simple mappings
    $localDonation->amount->set($remoteDonation->amount->get());
    $localDonation->receiveDate->set($remoteDonation->createdDate->get());
    $localDonation->currency->set(strtoupper($remoteDonation->currency->get()));

    // Find financial type
    $localDonation->financialTypeId->set($this->mapRemoteFinancialTypeToLocal($remoteDonation));

    // Recurrence
    if ($remoteDonation->recurrence->get()['recurring'] ?? FALSE) {
      // This is a recurring.
      // @todo discuss: how do we want to represent this?
      // could give the local donation special isRecurring and recurringPeriod properties.
      // then write a ContributionRecur record on save, where there isn't one.
      // (we'd need to ensure not to create a new recur every contribution.)
      // $period = $remoteDonation->recurrence->get()['period'];
    } 

    ['method' => $remotePaymentMethod, 'reference_number' => $remoteTrxnId] = $remoteDonation->payment->get();
    $localDonation->paymentInstrumentId->set($this->mapRemotePaymentMethodToLocalId($remotePaymentMethod));
    // $localDonation->paymentMethodId->set($this->mapRemotePaymentMethodToLocalId($remotePaymentMethod));

    // @todo possibly use a prefix?
    $localDonation->trxnId->set($remoteTrxnId);

    $this->mapRemoteFundraisingPageToLocal($remoteDonation, $localDonation);

    // @todo $remoteDonation->referrerData 

    return $localDonation;
  }

  /**
   * This basic implementation stores the remote fundraising page title in the local Contribution's source field.
   *
   * Other implementations might wish to store this elsewhere, e.g. a custom field.
   */
  protected function mapRemoteFundraisingPageToLocal(
    RemoteObjectInterface $remoteDonation,
    LocalObjectInterface $localDonation
  ): void {

    if (!($remoteDonation instanceof RemoteDonation)) {
      throw new CannotMapException(__CLASS__ . " requires the remote donation to be an ActionNetwork remote donation, received " . get_class($remoteDonation));
    }

    /** @var RemoteDonation $remoteDonation */
    $localDonation->source->set($remoteDonation->getFundraisingPage()->title->get());
  }

  /**
   * Using a cached lookup of local financial types, find the ID of the
   * financial type matching the remote donation.
   */
  protected function mapRemoteFinancialTypeToLocal(RemoteDonation $remoteDonation) :int {
    if (!isset(static::$financialTypesMap)) {
      static::$financialTypesMap = \Civi\Api4\FinancialType::get(FALSE)
      ->addWhere('is_active', '=', TRUE)
      ->addSelect('name')
      ->execute()->indexBy('name')->column('id');
    }

    if (count($remoteDonation->recipients->get()) !== 1) {
      throw new CannotMapException("Cannot sync a donation that does not have one and only one recipient.");
    }
    $financialTypeName = $remoteDonation->recipients->get()[0]['display_name'] ?? NULL;
    if (!$financialTypeName) {
      throw new CannotMapException("Cannot sync a donation that does not have a 'display_name' for its recipients.");
    }

    // Refuse to sync if financial type not declared.
    // (alternative behaviour: create the financial type if we're happy to
    //  expose this? @todo discuss. Note that we're a mapper who "must not
    //  create entities"...)
    if (!isset(static::$financialTypesMap[$financialTypeName])) {
      throw new CannotMapException("Cannot sync a donation for '{$financialTypeName}' as there is no matching financial type.");
    }

    return (int) static::$financialTypesMap[$financialTypeName];
  }

  /**
   *
   * Note the local 'id' here is found in OptionValue.value, not OptionValue.id
   */
  protected function mapRemotePaymentMethodToLocalId(string $remotePaymentMethod) :int {
    if (!isset(static::$paymentInstrumentsMap)) {
      static::$paymentInstrumentsMap = \Civi\Api4\OptionValue::get(FALSE)
      ->addSelect('label', 'value')
      ->addwhere('option_group_id:name', '=', 'payment_instrument')
      ->addWhere('is_active', '=', TRUE)
      ->addOrderBy('id', 'DESC') // Ensure we pick the first one if there are two with identical labels.
      ->execute()->indexBy('label')->column('value');
      // print json_encode(static::$paymentInstrumentsMap, JSON_PRETTY_PRINT);
    }
    if (empty(static::$paymentInstrumentsMap[$remotePaymentMethod])) {
      throw new CannotMapException("Cannot sync a donation made by '{$remotePaymentMethod}' as there is no matching payment instrument.");
    }
    return (int) static::$paymentInstrumentsMap[$remotePaymentMethod];
  }
}
