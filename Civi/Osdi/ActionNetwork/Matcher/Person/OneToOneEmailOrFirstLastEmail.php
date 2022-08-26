<?php

namespace Civi\Osdi\ActionNetwork\Matcher\Person;

use Civi\Osdi\ActionNetwork\RemoteFindResult;
use Civi\Osdi\ActionNetwork\RemoteSystem;
use Civi\Osdi\Exception\AmbiguousResultException;
use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\LocalObject\LocalObjectInterface;
use Civi\Osdi\LocalObject\Person as LocalPerson;
use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\MatcherInterface;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\Result\Match;

class OneToOneEmailOrFirstLastEmail implements MatcherInterface {

  protected RemoteSystem $system;

  protected string $localPersonClass;

  public function __construct(
    \Civi\Osdi\RemoteSystemInterface $system,
    string $localPersonClass = NULL
  ) {
    /** @var \Civi\Osdi\ActionNetwork\RemoteSystem $system */
    $this->system = $system;
    $this->localPersonClass = $localPersonClass ?? LocalPerson::class;
  }

  public function tryToFindMatchFor(LocalRemotePair $pair): Match {
    $result = $pair->isOriginLocal()
      ? $this->tryToFindMatchForLocalObject($pair)
      : $this->tryToFindMatchForRemoteObject($pair);
    $pair->getResultStack()->push($result);
    return $result;
  }

  public function tryToFindMatchForLocalObject(LocalRemotePair $pair): Match {
    $localObject = $pair->getLocalObject();
    try {
      $localObject->loadOnce();
    }
    catch (InvalidArgumentException $e) {
      return new Match(
        Match::ORIGIN_LOCAL,
        $localObject,
        NULL,
        Match::ERROR_INVALID_ID,
        'Bad contact id',
        $e
      );
    }

    if (empty($email = $localObject->emailEmail->get())) {
      return new Match(
        Match::ORIGIN_LOCAL,
        $localObject,
        NULL,
        Match::ERROR_MISSING_DATA,
        'Insufficient data in source contact to match on: email needed'
      );
    }

    $civiContactsWithEmail = $this->getCiviContactsBy($email);
    if ($civiContactsWithEmail->count() > 1) {
      return $this->findRemoteMatchByEmailAndName(
        $localObject
      );
    }

    $remoteSystemFindResult = $this->system->find('osdi:people',
      [['email', 'eq', $email]]);

    return $this->makeSingleOrZeroMatchResult(
      $localObject,
      $remoteSystemFindResult,
      'Matched on unique email'
    );
  }

  private function findRemoteMatchByEmailAndName(LocalObjectInterface $localPerson): Match {
    $email = $localPerson->emailEmail->get();
    $firstName = $localPerson->firstName->get() ?? '';
    $lastName = $localPerson->lastName->get() ?? '';
    $civiApi4Result = $this->getCiviContactsBy($email, $firstName, $lastName);
    $countOfCiviContactsWithSameEmailFirstLast = $civiApi4Result->count();

    if ($countOfCiviContactsWithSameEmailFirstLast > 1) {
      return new Match(
        Match::ORIGIN_LOCAL,
        $localPerson,
        NULL,
        Match::ERROR_INDETERMINATE,
        'The email, first name and last name of the source CiviCRM contact are not unique in CiviCRM',
        $civiApi4Result
      );
    }

    $remoteSystemFindResult = $this->system->find(
      'osdi:people',
      [
        ['email', 'eq', $email],
        ['given_name', 'eq', $firstName],
        ['last_name', 'eq', $lastName],
      ]
    );

    if (0 === $remoteSystemFindResult->rawCurrentCount()) {
      return new Match(
        Match::ORIGIN_LOCAL,
        $localPerson,
        NULL,
        Match::ERROR_INDETERMINATE,
        'The email of the source CiviCRM contact is not unique, and no remote match was found by email, first name and last name.',
        $civiApi4Result
      );
    }

    return $this->makeSingleOrZeroMatchResult(
      $localPerson,
      $remoteSystemFindResult,
      'Matched on email, first name and last name'
    );
  }

  public function tryToFindMatchForRemoteObject(LocalRemotePair $pair): Match {
    $remoteObject = $pair->getRemoteObject();

    if (empty($email = $remoteObject->emailAddress->get())) {
      return new Match(
        Match::ORIGIN_REMOTE,
        NULL,
        $remoteObject,
        Match::ERROR_MISSING_DATA,
        'Insufficient data in source contact to match on: email needed');
    }

    $civiContactsWithEmail = $this->getCiviContactsBy($email);

    if ($civiContactsWithEmail->count() === 1) {
      return new Match(
        Match::ORIGIN_REMOTE,
        new $this->localPersonClass($civiContactsWithEmail->first()['id']),
        $remoteObject,
        NULL,
        'Matched by unique email address'
      );
    }

    if ($civiContactsWithEmail->count() === 0) {
      return new Match(
        Match::ORIGIN_REMOTE,
        NULL,
        $remoteObject,
        Match::NO_MATCH,
        'No match by email');
    }

    //$civiContactsWithEmail->count() > 1
    return $this->findLocalMatchByEmailAndName($remoteObject);
  }

  private function findLocalMatchByEmailAndName(RemoteObjectInterface $remotePerson): Match {
    $civiApi4Result = $this->getCiviContactsBy(
      $remotePerson->emailAddress->get(),
      $remotePerson->givenName->get(),
      $remotePerson->familyName->get()
    );
    $countOfCiviContactsWithSameEmailFirstLast = $civiApi4Result->count();

    if ($countOfCiviContactsWithSameEmailFirstLast > 1) {
      return new Match(
        Match::ORIGIN_REMOTE,
        NULL,
        $remotePerson,
        Match::ERROR_INDETERMINATE,
        'The email, first name and last name of the source Action Network person have more than one match in CiviCRM',
        $civiApi4Result);
    }

    if ($countOfCiviContactsWithSameEmailFirstLast === 0) {
      return new Match(
        Match::ORIGIN_REMOTE,
        NULL,
        $remotePerson,
        Match::ERROR_INDETERMINATE,
        'Multiple matches by email, but no match by email, first name and last name',
        $civiApi4Result);
    }

    return new Match(
      Match::ORIGIN_REMOTE,
      new $this->localPersonClass($civiApi4Result->first()['id']),
      $remotePerson,
      NULL,
      'Matched on email, first name and last name'
    );
  }

  private function getCiviContactsBy(
    string $email,
    string $firstName = NULL,
    string $lastName = NULL
  ): \Civi\Api4\Generic\Result {
    $apiParams = [
      'checkPermissions' => FALSE,
      'select' => ['id'],
      'join' => LocalPerson::JOINS,
      'groupBy' => ['id'],
    ];
    $apiParams['where'] = [
      ['email.email', '=', $email],
      ['contact_type', '=', 'Individual'],
      ['is_deleted', '=', 0],
    ];
    if ($firstName !== NULL) {
      $apiParams['where'][] = ['first_name', '=', trim($firstName)];
    }
    if ($lastName !== NULL) {
      $apiParams['where'][] = ['last_name', '=', trim($lastName)];
    }
    return civicrm_api4('Contact', 'get', $apiParams);
  }

  private function makeSingleOrZeroMatchResult(LocalObjectInterface $localPerson,
                                               RemoteFindResult $collection,
                                               string $message): Match {
    if (($count = $collection->rawCurrentCount()) > 1) {
      throw new AmbiguousResultException('At most one match expected, %d returned', $count);
    }
    try {
      return new Match(
        Match::ORIGIN_LOCAL,
        $localPerson,
        $collection->rawFirst(),
        NULL,
        $message);
    }
    catch (EmptyResultException $e) {
      return new Match(
        Match::ORIGIN_LOCAL,
        $localPerson,
        NULL,
        Match::NO_MATCH,
        'No match by email, first name and last name');
    }
  }

}
