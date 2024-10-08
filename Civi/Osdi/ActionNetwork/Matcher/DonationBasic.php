<?php
namespace Civi\Osdi\ActionNetwork\Matcher;

use Civi\Api4\Contribution;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Result\MatchResult as MatchResult;
use Civi\OsdiClient;

class DonationBasic extends AbstractMatcher implements \Civi\Osdi\MatcherInterface {

  protected function tryToFindMatchForLocalObject(LocalRemotePair $pair): MatchResult {
    $container = OsdiClient::container();
    $result = new MatchResult($pair->getOrigin());

    /** @var \Civi\Osdi\LocalObject\DonationBasic $localDonation */
    $localDonation = $pair->getLocalObject()->loadOnce();
    $localPerson = $localDonation->getPerson()->loadOnce();
    $personPair = new LocalRemotePair($localPerson);
    $personPair->setOrigin(LocalRemotePair::ORIGIN_LOCAL);
    $personSyncer = $container->getSingle('SingleSyncer', 'Person');
    $personMatchResult = $personSyncer->fetchOldOrFindAndSaveNewMatch($personPair);

    if (!$personMatchResult->hasMatch()) {
      $result->setStatusCode(MatchResult::NO_MATCH);
      $result->setMessage('No match found for donor');
      $result->setContext($personMatchResult);
      return $result;
    }

    // We found a matching person
    $remotePerson = $personPair->getRemoteObject();
    $remoteDonations = $remotePerson->getDonations();

    foreach ($remoteDonations as $remoteDonation) {
      /** @var \Civi\Osdi\ActionNetwork\Object\Donation $remoteDonation */
      $remoteDonation->loadOnce();

      $localAmount = (float) $localDonation->amount->get();
      $remoteAmount = (float) $remoteDonation->amount->get();
      $localSystem = $container->getSingle('LocalSystem', 'Civi');
      $localTime = $localDonation->receiveDate->get() ?? '';
      $remoteTime = $remoteDonation->createdDate->get();
      $remoteTimeLocalized = $remoteTime
        ? $localSystem->convertToLocalizedDateTimeString(new \DateTime($remoteTime))
        : '';

      if (($localAmount === $remoteAmount) && ($localTime === $remoteTimeLocalized)) {
        $result->setStatusCode(MatchResult::FOUND_MATCH);
        $result->setMessage('Matched on person, timestamp and amount');
        $result->setMatch($remoteDonation);
        $result->setContext([
          'local id' => $localDonation->getId(),
          'remote id' => $remoteDonation->getId(),
        ]);
        $pair->setRemoteObject($remoteDonation);
        return $result;
      }
    }

    // If we reached this point, there's no match
    $result->setStatusCode(MatchResult::NO_MATCH);
    $result->setMessage('Matching person found, but they have no matching donations by timestamp and amount');
    $result->setContext([
      'local contact id' => $localPerson->getId(),
      'remote person id' => $remotePerson->getId(),
    ]);
    return $result;
  }

  protected function tryToFindMatchForRemoteObject(LocalRemotePair $pair): MatchResult {
    $container = OsdiClient::container();
    $result = new MatchResult($pair->getOrigin());

    /** @var \Civi\Osdi\ActionNetwork\Object\Donation $remoteDonation */
    $remoteDonation = $pair->getRemoteObject();
    $remotePerson = $remoteDonation->getDonor();

    if (!$remotePerson) {
      $result->setStatusCode(MatchResult::ERROR_INVALID_ID);
      $result->setMessage("Phantom donation: Action Network won't let us access the donor");
      return $result;
    }

    $personPair = new LocalRemotePair(NULL, $remotePerson);
    $personPair->setOrigin(LocalRemotePair::ORIGIN_REMOTE);
    $personSyncer = $container->getSingle('SingleSyncer', 'Person');
    $personMatchResult = $personSyncer->fetchOldOrFindAndSaveNewMatch($personPair);

    if (!$personMatchResult->hasMatch()) {
      $result->setStatusCode(MatchResult::NO_MATCH);
      $result->setMessage('No match found for donor');
      $result->setContext($personMatchResult);
      return $result;
    }

    // We found a matching person
    $localPerson = $personPair->getLocalObject();
    $remoteAmount = (float) $remoteDonation->amount->get();
    $remoteTimeString = $remoteDonation->createdDate->get();
    $localSystem = $container->getSingle('LocalSystem', 'Civi');
    $localizedTimeString = $remoteTimeString
      ? $localSystem->convertToLocalizedDateTimeString(new \DateTime($remoteTimeString))
      : '';

    $selects = $container->callStatic('LocalObject', 'Donation', 'getSelects');
    $localDonations = Contribution::get(FALSE)
      ->setSelect(array_keys($selects))
      ->addWhere('contact_id', '=', $localPerson->getId())
      ->addWhere('total_amount', '=', $remoteAmount)
      ->addWhere('receive_date', '=', $localizedTimeString)
      ->execute();

    $matchCount = $localDonations->count();
    if ($matchCount > 1) {
      $result->setStatusCode(MatchResult::ERROR_INDETERMINATE);
      $result->setMessage($matchCount . ' contributions matched on timestamp and amount');
      $result->setContext($localDonations);
      return $result;
    }

    $matchingLocalDonation = $localDonations->first();
    if ($matchingLocalDonation) {
      $result->setStatusCode(MatchResult::FOUND_MATCH);
      $result->setMessage('Matched on person, timestamp and amount');
      $result->setContext([
        'remote id' => $remoteDonation->getId(),
        'local id' => $matchingLocalDonation['id'],
      ]);
      $localObject = $container->make(
        'LocalObject', 'Donation', $matchingLocalDonation['id']);
      $result->setMatch($localObject);
      $pair->setLocalObject($localObject);
      return $result;
    }

    // If we reached this point, there's no match
    $result->setStatusCode(MatchResult::NO_MATCH);
    $result->setMessage('Matching person found, but they have no matching donations by timestamp and amount');
    $result->setContext([
      'remote person id' => $remotePerson->getId(),
      'local contact id' => $localPerson->getId(),
    ]);
    $pair->getResultStack()->push($result);
    return $result;
  }

}

