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

  public function tryToFindMatchForLocalContact(LocalPerson $localPerson) {
    try {
      $localPerson->loadOnce();
    }
    catch (InvalidArgumentException $e) {
      return new MatchResult($localPerson, [], MatchResult::ERROR_INVALID_ID);
    }

    if (empty($email = $localPerson->emailEmail->get())) {
      return new MatchResult(
        $localPerson,
        [],
        MatchResult::ERROR_MISSING_DATA,
        'Insufficient data in source contact to match on'
      );
    }

    $civiContactsWithEmail = $this->getCiviContactsBy($email);
    if ($civiContactsWithEmail->count() > 1) {
      return $this->findRemoteMatchByEmailAndName(
        $localPerson
      );
    }

    $remoteSystemFindResult = $this->system->find('osdi:people', [
      [
        'email',
        'eq',
        $email,
      ],
    ]);
    return $this->makeSingleOrZeroMatchResult($localPerson, $remoteSystemFindResult);
  }

  private function findRemoteMatchByEmailAndName(LocalPerson $localPerson): MatchResult {
    $email = $localPerson->emailEmail->get();
    $firstName = $localPerson->firstName->get() ?? '';
    $lastName = $localPerson->lastName->get() ?? '';
    $civiApi4Result = $this->getCiviContactsBy($email, $firstName, $lastName);
    $countOfCiviContactsWithSameEmailFirstLast = $civiApi4Result->count();
    if ($countOfCiviContactsWithSameEmailFirstLast > 1) {
      return new MatchResult(
        $localPerson,
        [],
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
    if (0 === $remoteSystemFindResult->filteredCurrentCount()) {
      return new MatchResult(
        $localPerson,
        [],
        MatchResult::ERROR_INDETERMINATE,
        'The email of the source CiviCRM contact is not unique, and no remote match was found by email, first name and last name.',
        $civiApi4Result
      );
    }
    return $this->makeSingleOrZeroMatchResult($localPerson, $remoteSystemFindResult);
  }

  public function tryToFindMatchForRemotePerson(RemotePerson $remotePerson) {
    if (empty($email = $remotePerson->getEmailAddress())) {
      return new MatchResult(
        $remotePerson,
        [],
        MatchResult::ERROR_MISSING_DATA, 'Insufficient data in source contact to match on');
    }

    $civiContactsWithEmail = $this->getCiviContactsBy($email);
    if ($civiContactsWithEmail->count() === 1) {
      return new MatchResult(
        $remotePerson,
        [new LocalPerson($civiContactsWithEmail->first()['id'])]
      );
    }

    if ($civiContactsWithEmail->count() === 0) {
      return new MatchResult($remotePerson, [], MatchResult::NO_MATCH, 'No match by email');
    }

    if ($civiContactsWithEmail->count() > 1) {
      return $this->findLocalMatchByEmailAndName($remotePerson);
    }
  }

  private function findLocalMatchByEmailAndName(RemotePerson $remotePerson) {
    $civiApi4Result = $this->getCiviContactsBy(
      $remotePerson->getEmailAddress(),
      $remotePerson->getOriginal('given_name'),
      $remotePerson->getOriginal('family_name')
    );
    $countOfCiviContactsWithSameEmailFirstLast = $civiApi4Result->count();

    if ($countOfCiviContactsWithSameEmailFirstLast > 1) {
      foreach ($civiApi4Result as $contactArray) {
        $localPeople[] = new LocalPerson($contactArray['id']);
      }
      return new MatchResult(
        $remotePerson,
        $localPeople,
        MatchResult::ERROR_INDETERMINATE,
        'The email, first name and last name of the source CiviCRM contact are not unique in CiviCRM',
        $civiApi4Result);
    }

    if ($countOfCiviContactsWithSameEmailFirstLast === 0) {
      return new MatchResult(
        $remotePerson,
        [],
        MatchResult::ERROR_INDETERMINATE,
        'Multiple matches by email, but no match by email, first name and last name',
        $civiApi4Result);
    }

    return new MatchResult(
      $remotePerson,
      [new LocalPerson($civiApi4Result->first()['id'])]
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
      array_push($apiParams['where'], ['first_name', '=', $firstName]);
    }
    if ($lastName !== NULL) {
      array_push($apiParams['where'], ['last_name', '=', $lastName]);
    }
    return civicrm_api4('Contact', 'get', $apiParams);
  }

  /**
   * @param $originObject *
   * @param \Civi\Osdi\ResultCollection $collection
   *
   * @return \Civi\Osdi\MatchResult
   * @throws \Civi\Osdi\Exception\AmbiguousResultException
   */
  private function makeSingleOrZeroMatchResult($originObject, ResultCollection $collection): MatchResult {
    if (($count = $collection->filteredCurrentCount()) > 1) {
      throw new AmbiguousResultException('At most one match expected, %d returned', $count);
    }
    try {
      return new MatchResult($originObject, [$collection->filteredFirst()]);
    }
    catch (EmptyResultException $e) {
      return new MatchResult($originObject, [], MatchResult::NO_MATCH, 'No match by email, first name and last name');
    }
  }

}
