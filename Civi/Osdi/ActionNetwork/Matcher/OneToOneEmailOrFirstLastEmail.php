<?php

namespace Civi\Osdi\ActionNetwork\Matcher;

use Civi\Osdi\ActionNetwork\Object\Person as RemotePerson;
use Civi\Osdi\Exception\AmbiguousResultException;
use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\Exception\InvalidArgumentException;
use Civi\Osdi\LocalObject\Person as LocalPerson;
use Civi\Osdi\MatchResult;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\ResultCollection;

class OneToOneEmailOrFirstLastEmail {

  protected RemoteSystemInterface $system;

  protected \Civi\Osdi\ActionNetwork\Syncer\Person $syncer;

  /**
   * OneToOneEmailOrFirstLastEmail constructor.
   */
  public function __construct(\Civi\Osdi\ActionNetwork\Syncer\Person $syncer) {
    $this->syncer = $syncer;
    $this->system = $syncer->getRemoteSystem();
  }

  public function tryToFindMatchForLocalContact(LocalPerson $localPerson): MatchResult {
    try {
      $localPerson->loadOnce();
    }
    catch (InvalidArgumentException $e) {
      return new MatchResult(
        MatchResult::ORIGIN_LOCAL,
        $localPerson,
        NULL,
        MatchResult::ERROR_INVALID_ID,
        'Bad contact id',
        $e
      );
    }

    if (empty($email = $localPerson->emailEmail->get())) {
      return new MatchResult(
        MatchResult::ORIGIN_LOCAL,
        $localPerson,
        NULL,
        MatchResult::ERROR_MISSING_DATA,
        'Insufficient data in source contact to match on: email needed'
      );
    }

    $civiContactsWithEmail = $this->getCiviContactsBy($email);
    if ($civiContactsWithEmail->count() > 1) {
      return $this->findRemoteMatchByEmailAndName(
        $localPerson
      );
    }

    $remoteSystemFindResult = $this->system->find('osdi:people',
      [['email', 'eq', $email]]);
    return $this->makeSingleOrZeroMatchResult(
      $localPerson,
      $remoteSystemFindResult,
      'Matched on unique email'
    );
  }

  private function findRemoteMatchByEmailAndName(LocalPerson $localPerson): MatchResult {
    $email = $localPerson->emailEmail->get();
    $firstName = $localPerson->firstName->get() ?? '';
    $lastName = $localPerson->lastName->get() ?? '';
    $civiApi4Result = $this->getCiviContactsBy($email, $firstName, $lastName);
    $countOfCiviContactsWithSameEmailFirstLast = $civiApi4Result->count();

    if ($countOfCiviContactsWithSameEmailFirstLast > 1) {
      return new MatchResult(
        MatchResult::ORIGIN_LOCAL,
        $localPerson,
        NULL,
        MatchResult::ERROR_INDETERMINATE,
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
      return new MatchResult(
        MatchResult::ORIGIN_LOCAL,
        $localPerson,
        NULL,
        MatchResult::ERROR_INDETERMINATE,
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

  public function tryToFindMatchForRemotePerson(RemotePerson $remotePerson): MatchResult {
    if (empty($email = $remotePerson->emailAddress->get())) {
      return new MatchResult(
        MatchResult::ORIGIN_REMOTE,
        NULL,
        $remotePerson,
        MatchResult::ERROR_MISSING_DATA,
        'Insufficient data in source contact to match on: email needed');
    }

    $civiContactsWithEmail = $this->getCiviContactsBy($email);

    if ($civiContactsWithEmail->count() === 1) {
      return new MatchResult(
        MatchResult::ORIGIN_REMOTE,
        new LocalPerson($civiContactsWithEmail->first()['id']),
        $remotePerson,
        NULL,
        'Matched by unique email address'
      );
    }

    if ($civiContactsWithEmail->count() === 0) {
      return new MatchResult(
        MatchResult::ORIGIN_REMOTE,
        NULL,
        $remotePerson,
        MatchResult::NO_MATCH,
        'No match by email');
    }

    //$civiContactsWithEmail->count() > 1
    return $this->findLocalMatchByEmailAndName($remotePerson);
  }

  private function findLocalMatchByEmailAndName(RemotePerson $remotePerson): MatchResult {
    $civiApi4Result = $this->getCiviContactsBy(
      $remotePerson->emailAddress->get(),
      $remotePerson->givenName->getOriginal(),
      $remotePerson->familyName->getOriginal()
    );
    $countOfCiviContactsWithSameEmailFirstLast = $civiApi4Result->count();

    if ($countOfCiviContactsWithSameEmailFirstLast > 1) {
      return new MatchResult(
        MatchResult::ORIGIN_REMOTE,
        NULL,
        $remotePerson,
        MatchResult::ERROR_INDETERMINATE,
        'The email, first name and last name of the source CiviCRM contact are not unique in CiviCRM',
        $civiApi4Result);
    }

    if ($countOfCiviContactsWithSameEmailFirstLast === 0) {
      return new MatchResult(
        MatchResult::ORIGIN_REMOTE,
        NULL,
        $remotePerson,
        MatchResult::ERROR_INDETERMINATE,
        'Multiple matches by email, but no match by email, first name and last name',
        $civiApi4Result);
    }

    return new MatchResult(
      MatchResult::ORIGIN_REMOTE,
      new LocalPerson($civiApi4Result->first()['id']),
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
    ];
    $apiParams['where'] = [
        ['email.email', '=', $email],
        ['is_deleted', '=', 0],
    ];
    if ($firstName !== NULL) {
      $apiParams['where'][] = ['first_name', '=', $firstName];
    }
    if ($lastName !== NULL) {
      $apiParams['where'][] = ['last_name', '=', $lastName];
    }
    return civicrm_api4('Contact', 'get', $apiParams);
  }

  private function makeSingleOrZeroMatchResult(LocalPerson $localPerson,
                                               ResultCollection $collection,
                                               string $message): MatchResult {
    if (($count = $collection->rawCurrentCount()) > 1) {
      throw new AmbiguousResultException('At most one match expected, %d returned', $count);
    }
    try {
      return new MatchResult(
        MatchResult::ORIGIN_LOCAL,
        $localPerson,
        $collection->rawFirst(),
        NULL,
        $message);
    }
    catch (EmptyResultException $e) {
      return new MatchResult(
        MatchResult::ORIGIN_LOCAL,
        $localPerson,
        NULL,
        MatchResult::NO_MATCH,
        'No match by email, first name and last name');
    }
  }

}
