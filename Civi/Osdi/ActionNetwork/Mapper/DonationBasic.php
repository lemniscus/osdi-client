<?php

namespace Civi\Osdi\ActionNetwork\Mapper;

use Civi\Osdi\ActionNetwork\Object\Donation as RemoteDonation;
use Civi\Osdi\ActionNetwork\RemoteFindResult;
use Civi\Osdi\Exception\CannotMapException;
use Civi\Osdi\LocalObjectInterface;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\MapperInterface;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\Result\Map as MapResult;
use Civi\OsdiClient;

class DonationBasic implements MapperInterface {

  const FUNDRAISING_PAGE_NAME = 'CiviCRM';

  protected static RemoteFindResult $remoteFundraisingPages;
  protected static array $financialTypesMap;
  protected static array $paymentInstrumentsMap;
  private RemoteSystemInterface $remoteSystem;

  public function __construct(?RemoteSystemInterface $remoteSystem = NULL) {
    $this->remoteSystem = $remoteSystem ??
      OsdiClient::container()->getSingle('RemoteSystem', 'ActionNetwork');
  }

  /**
   * Map values from $pair's origin to $pair's target. This function delegates
   * to directional mapping functions; it catches exceptions and stores them in
   * the result object.
   */
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
    catch (CannotMapException $e) {
      $result->setStatusCode($result::ERROR);
      $result->setMessage($e->getMessage());
      $localObject = $pair->getLocalObject();
      $remoteObject = $pair->getRemoteObject();
      $result->setContext([
        'origin' => $pair->getOrigin(),
        'local donation' => $localObject ? $localObject->getAllWithoutLoading() : NULL,
        'remote donation' => $remoteObject ? $remoteObject->getArrayForCreate() : NULL,
      ]);
    }
    catch (\Throwable $e) {
      $result->setStatusCode($result::ERROR);
      $result->setMessage($e->getMessage());
      $result->setContext(['exception' => $e]);
    }

    $pair->getResultStack()->push($result);
    return $result;
  }

  /**
   * Return $remoteDonation after setting various fields based on
   * $localDonation.
   *
   * First, find a matching remote person (overwriting anyone attached to the
   * existing remote donation), or throw an error. Link the remote person to
   * the remote donation. Copy from local to remote: currency, amount,
   * recurrence. Copy date received -> created date, financial type ->
   * recipients:display_name. Link the remote donation to the AN Fundraising
   * Page called "CiviCRM" (which must exist). Note, payment method and
   * reference number cannot be written by the API, so they are not mapped.
   *
   * @todo instead of taking two objects as params, take a LocalRemotePair and
   * add to its result stack. That's a more powerful way to track status than
   * throwing exceptions.
   *
   * @throws \Civi\Osdi\Exception\CannotMapException
   * @throws \Civi\Osdi\Exception\InvalidArgumentException
   * @throws \Civi\Osdi\Exception\InvalidOperationException
   */
  public function mapLocalToRemote(
    LocalObjectInterface $localDonation,
    RemoteObjectInterface $remoteDonation = NULL
  ): RemoteObjectInterface {
    $localDonation->loadOnce();
    $remoteDonation = $remoteDonation ?? OsdiClient::container()->make(
      'OsdiObject', 'osdi:donations', $this->remoteSystem);

    $this->mapLocalDonorToRemote($localDonation, $remoteDonation);
    $this->mapLocalBasicFieldsToRemote($localDonation, $remoteDonation);
    $this->mapLocalRecurrenceToRemote($localDonation, $remoteDonation);
    $this->mapLocalFundraisingPageToRemote(static::FUNDRAISING_PAGE_NAME, $localDonation, $remoteDonation);

    // This data is ignored by AN so no point trying to set it.
    // $remoteDonation->payment = ['method' => 'EFT', 'reference_number' => 'test_payment_1'];

    return $remoteDonation;
  }

  /**
   * Return $localDonation after setting various fields based on
   * $remoteDonation.
   *
   * First, find a matching local person (overwriting the id of anyone attached
   * to the existing local donation), or throw an error. Link the local person
   * to the local donation. Copy from local to remote: amount and currency.
   * Copy created date -> date received, reference number -> transaction id,
   * fundraising page title -> source. Find the Civi financial type whose name
   * matches the AN donation's recipient display_name or throw an error.
   * Likewise with payment method.
   *
   * @todo instead of taking two objects as params, take a LocalRemotePair and
   * add to its result stack.
   */
  public function mapRemoteToLocal(
    RemoteObjectInterface $remoteDonation,
    LocalObjectInterface $localDonation = NULL
  ): LocalObjectInterface {
    $container = OsdiClient::container();

    /** @var \Civi\Osdi\LocalObject\DonationBasic $localDonation */
    $localDonation = $localDonation ?? $container->make('LocalObject', 'Donation');

    // Load the person that the donation belongs to.
    /** @var \Civi\Osdi\ActionNetwork\Object\Donation $remoteDonation */
    $remotePerson = $remoteDonation->getDonor();
    $personPair = new LocalRemotePair(NULL, $remotePerson);
    $personPair->setOrigin(LocalRemotePair::ORIGIN_REMOTE);
    /** @var \Civi\Osdi\SingleSyncerInterface $personSyncer */
    $personSyncer = $container->getSingle('SingleSyncer', 'Person', $this->remoteSystem);
    $matchResult = $personSyncer->fetchOldOrFindAndSaveNewMatch($personPair);
    if (!$matchResult->hasMatch()) {
      throw new CannotMapException('Cannot sync a donation whose Person'
        . ' (%s) has no LocalPerson match.', $remotePerson->getId());
    }
    $localPerson = $personPair->getLocalObject();
    $localDonation->setPerson($localPerson);

    // Simple mappings
    $localDonation->amount->set($remoteDonation->amount->get());
    $localDonation->receiveDate->set($remoteDonation->createdDate->get());
    $localDonation->currency->set(strtoupper($remoteDonation->currency->get()));

    // Find financial type
    $localDonation->financialTypeId->set(
      $this->mapRemoteFinancialTypeToLocal($remoteDonation));

    // Recurrence
    //if ($remoteDonation->recurrence->get()['recurring'] ?? FALSE) {
    //  // This is a recurring.
    //  // @todo discuss: how do we want to represent this?
    //  // could give the local donation special isRecurring and recurringPeriod properties.
    //  // then write a ContributionRecur record on save, where there isn't one.
    //  // (we'd need to ensure not to create a new recur every contribution.)
    //  // $period = $remoteDonation->recurrence->get()['period'];
    //}

    ['method' => $remotePaymentMethod, 'reference_number' => $remoteTrxnId]
      = $remoteDonation->payment->get();

    $localDonation->paymentInstrumentId->set(
      $this->mapRemotePaymentMethodToLocalId($remotePaymentMethod));

    // @todo possibly use a prefix?
    $localDonation->trxnId->set($remoteTrxnId);

    $this->mapRemoteFundraisingPageToLocal($remoteDonation, $localDonation);

    // @todo $remoteDonation->referrerData

    return $localDonation;
  }

  /**
   * Using a cached lookup of local financial types, find the ID of the
   * financial type matching the remote donation.
   */
  protected function mapRemoteFinancialTypeToLocal(RemoteDonation $remoteDonation): int {
    $financialTypesMap =& \Civi::$statics['osdiClient.financialTypesMap'];
    if (!isset($financialTypesMap)) {
      $financialTypesMap = \Civi\Api4\FinancialType::get(FALSE)
        ->addWhere('is_active', '=', TRUE)
        ->addSelect('name')
        ->execute()->indexBy('name')->column('id');
    }

    if (count($remoteDonation->recipients->get()) !== 1) {
      throw new CannotMapException('Cannot sync a donation that does '
        . 'not have one and only one recipient.');
    }
    $financialTypeName = $remoteDonation->recipients->get()[0]['display_name'] ?? NULL;
    if (!$financialTypeName) {
      throw new CannotMapException("Cannot sync a donation that does"
        . " not have a 'display_name' for its recipients.");
    }

    // Refuse to sync if financial type not declared.
    // (alternative behaviour: create the financial type if we're happy to
    // expose this? @todo discuss. Note that we're a mapper who "must not
    // create entities"...)
    if (!isset($financialTypesMap[$financialTypeName])) {
      throw new CannotMapException("Cannot sync a donation for "
        . "'{$financialTypeName}' as there is no matching financial type.");
    }

    return (int) $financialTypesMap[$financialTypeName];
  }

  /**
   * This basic implementation stores the remote fundraising page title in the
   * local Contribution's source field.
   *
   * Other implementations might wish to store this elsewhere, e.g. a custom
   * field.
   */
  protected function mapRemoteFundraisingPageToLocal(
    RemoteObjectInterface $remoteDonation,
    LocalObjectInterface $localDonation
  ): void {
    /** @var \Civi\Osdi\ActionNetwork\Object\Donation $remoteDonation */
    $pageTitle = $remoteDonation->getFundraisingPage()->title->get();
    $localDonation->source->set("Action Network: $pageTitle");
  }

  /**
   *
   * Note the local 'id' here is found in OptionValue.value, not OptionValue.id
   */
  protected function mapRemotePaymentMethodToLocalId(string $remotePaymentMethod): int {
    if (!isset(static::$paymentInstrumentsMap)) {
      static::$paymentInstrumentsMap = \Civi\Api4\OptionValue::get(FALSE)
        ->addSelect('label', 'value')
        ->addwhere('option_group_id:name', '=', 'payment_instrument')
        ->addWhere('is_active', '=', TRUE)
        // OrderBy ensures we pick the first one if there are two with identical labels.
        ->addOrderBy('id', 'DESC')
        ->execute()->indexBy('label')->column('value');
    }
    if (empty(static::$paymentInstrumentsMap[$remotePaymentMethod])) {
      throw new CannotMapException("Cannot sync a donation made by "
        . "'{$remotePaymentMethod}' as there is no matching payment instrument.");
    }
    return (int) static::$paymentInstrumentsMap[$remotePaymentMethod];
  }

  /**
   * Map a string to an Action Network fundraising page.
   *
   * This is a challenge: on the Civi side we have just a string;
   * on the remote side we have an object. And we can't look up the
   * object by name.
   *
   * @param \Civi\Osdi\LocalObjectInterface $localDonation
   */
  protected function mapLocalFundraisingPageToRemote(string $title, LocalObjectInterface $localDonation, RemoteDonation $remoteDonation): void {
    $fundraisingPage = $this->findRemoteFundraisingPageByTitle($title);
    if ($fundraisingPage) {
      $remoteDonation->setFundraisingPage($fundraisingPage);
    }
    else {
      throw new CannotMapException('Cannot map local donation: Failed '
        . 'to find remote fundraising page called %s', json_encode($title));
    }
  }

  protected function mapLocalDonorToRemote($localDonation, RemoteDonation $remoteDonation): void {
    $localPerson = $localDonation->getPerson();
    $personPair = new LocalRemotePair($localPerson);
    $personPair->setOrigin(LocalRemotePair::ORIGIN_LOCAL);

    /** @var \Civi\Osdi\SingleSyncerInterface $personSyncer */
    $personSyncer = OsdiClient::container()->getSingle('SingleSyncer', 'Person');
    $matchResult = $personSyncer->fetchOldOrFindAndSaveNewMatch($personPair);
    if (!$matchResult->hasMatch()) {
      throw new CannotMapException("Cannot sync a donation whose Contact"
        . " (ID %d) has no RemotePerson match.", $localPerson->getId());
    }

    /** @var \Civi\Osdi\ActionNetwork\Object\Person $remotePerson */
    $remotePerson = $personPair->getRemoteObject();
    $remoteDonation->setDonor($remotePerson);
  }

  protected function mapLocalRecurrenceToRemote($localDonation, RemoteDonation $remoteDonation): void {
    if ($localDonation->contributionRecurId->get()) {
      $freq = $localDonation->contributionRecurFrequency->get();
      $period = [
        'year' => 'Yearly',
        'month' => 'Monthly',
        'week' => 'Weekly',
        'day' => 'Daily',
      ][$freq] ?? NULL;
      if (!$period) {
        throw new CannotMapException('Unsupported recurring period "%s"',
          $freq);
      }
      $remoteDonation->recurrence->set([
        'recurring' => TRUE,
        'period' => $period,
      ]);
    }
    else {
      $remoteDonation->recurrence->set(['recurring' => FALSE]);
    }
  }

  private function mapLocalBasicFieldsToRemote($localDonation, RemoteDonation $remoteDonation): void {
    $unixTimeStamp = strtotime($localDonation->receiveDate->get());
    $formattedTime = $this->remoteSystem::formatDateTime($unixTimeStamp);
    $remoteDonation->createdDate->set($formattedTime);
    $remoteDonation->currency->set(strtolower($localDonation->currency->get()));
    $recipient['display_name'] = $localDonation->financialTypeLabel->get();
    $recipient['amount'] = $localDonation->amount->get();
    $remoteDonation->recipients->set([$recipient]);
  }

  private function findRemoteFundraisingPageByTitle(string $title): ?RemoteObjectInterface {
    $pages = &$this->getRemoteFundraisingPageCache();

    foreach ($pages as $pageSet) {
      foreach ($pageSet as $fundraisingPage) {
        if ($fundraisingPage->title->get() === $title) {
          return $fundraisingPage;
        }
      }
    }

    return NULL;
  }

  protected function &getRemoteFundraisingPageCache() {
    $syncProfileId = OsdiClient::container()->getSyncProfileId();
    $pages = &\Civi::$statics[__CLASS__ . ':RemoteFundPages'][$syncProfileId];
    if (empty($pages)) {
      $pages['added'] = [];
      $pages['found'] = $this->remoteSystem->findAll('osdi:fundraising_pages');
    }
    return $pages;
  }

  protected function addRemoteFundraisingPageToCache(RemoteObjectInterface $page) {
    $pages = &$this->getRemoteFundraisingPageCache();
    $pages['added'][] = $page;
  }

}
