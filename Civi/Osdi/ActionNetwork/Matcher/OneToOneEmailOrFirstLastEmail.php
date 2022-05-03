<?php

namespace Civi\Osdi\ActionNetwork\Matcher;

use Civi\Osdi\ActionNetwork\Object\Person;
use Civi\Osdi\Exception\AmbiguousResultException;
use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\MatchResult;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\ResultCollection;

class OneToOneEmailOrFirstLastEmail {

  protected RemoteSystemInterface $system;

  protected \Civi\Osdi\ActionNetwork\Syncer\Person $syncer;

  private \Civi\Osdi\ActionNetwork\Mapper\Person $mapper;

  /**
   * OneToOneEmailOrFirstLastEmail constructor.
   */
  public function __construct(\Civi\Osdi\ActionNetwork\Syncer\Person $syncer) {
    $this->syncer = $syncer;
    $this->system = $syncer->getRemoteSystem();
    $this->mapper = $syncer->getMapper();
  }

  public function tryToFindMatchForLocalContact(int $id) {
    $api4ContactResult = $this->getContactById($id);
    if ($api4ContactResult->count() === 0) {
      return new MatchResult($id, [], MatchResult::ERROR_INVALID_ID);
    }

    $originContactArray = $api4ContactResult->first();
    if (empty($email = $originContactArray['email.email'])) {
      return new MatchResult(
        $originContactArray,
        [],
        MatchResult::ERROR_MISSING_DATA,
        'Insufficient data in source contact to match on'
      );
    }

    $civiContactsWithEmail = $this->getCiviContactsBy($email);
    if ($civiContactsWithEmail->count() > 1) {
      return $this->findRemoteMatchByEmailAndName(
        $originContactArray
      );
    }

    $remoteSystemFindResult = $this->system->find('osdi:people', [
      [
        'email',
        'eq',
        $email,
      ],
    ]);
    return $this->makeSingleOrZeroMatchResult($originContactArray, $remoteSystemFindResult);
  }

  private function findRemoteMatchByEmailAndName(array $originContactArray): MatchResult {
    $email = $originContactArray['email.email'];
    $firstName = $originContactArray['first_name'] ?? '';
    $lastName = $originContactArray['last_name'] ?? '';
    $civiApi4Result = $this->getCiviContactsBy($email, $firstName, $lastName);
    $countOfCiviContactsWithSameEmailFirstLast = $civiApi4Result->count();
    if ($countOfCiviContactsWithSameEmailFirstLast > 1) {
      return new MatchResult(
        $originContactArray,
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
        $originContactArray,
        [],
        MatchResult::ERROR_INDETERMINATE,
        'The email of the source CiviCRM contact is not unique, and no remote match was found by email, first name and last name.',
        $civiApi4Result
      );
    }
    return $this->makeSingleOrZeroMatchResult($originContactArray, $remoteSystemFindResult);
  }

  public function tryToFindMatchForRemotePerson(Person $remotePerson) {
    if (empty($email = $remotePerson->getEmailAddress())) {
      return new MatchResult(
        $remotePerson,
        [],
        MatchResult::ERROR_MISSING_DATA, 'Insufficient data in source contact to match on');
    }

    $civiContactsWithEmail = $this->getCiviContactsBy($email);
    if ($civiContactsWithEmail->count() === 1) {
      return new MatchResult($remotePerson, $civiContactsWithEmail->getArrayCopy());
    }

    if ($civiContactsWithEmail->count() === 0) {
      return new MatchResult($remotePerson, [], MatchResult::NO_MATCH, 'No match by email');
    }

    if ($civiContactsWithEmail->count() > 1) {
      return $this->findLocalMatchByEmailAndName($remotePerson);
    }
  }

  private function findLocalMatchByEmailAndName(Person $remotePerson) {
    $civiApi4Result = $this->getCiviContactsBy(
      $remotePerson->getEmailAddress(),
      $remotePerson->getOriginal('given_name'),
      $remotePerson->getOriginal('family_name')
    );
    $countOfCiviContactsWithSameEmailFirstLast = $civiApi4Result->count();

    if ($countOfCiviContactsWithSameEmailFirstLast > 1) {
      return new MatchResult(
        $remotePerson,
        [],
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

    return new MatchResult($remotePerson, $civiApi4Result->getArrayCopy());
  }

  /**
   * @throws \API_Exception
   */
  private function getContactById(int $id): \Civi\Api4\Generic\Result {
    $apiParams = $this->mapper->getFieldsToSelect();
    $apiParams['where'] = [['id', '=', $id]];
    $apiParams['limit'] = 2;
    $apiParams['checkPermissions'] = FALSE;

    return civicrm_api4('Contact', 'get', $apiParams);
  }

  private function getCiviContactsBy(
    string $email,
    string $firstName = NULL,
    string $lastName = NULL
  ): \Civi\Api4\Generic\Result {
    $apiParams = $this->mapper->getFieldsToSelect();
    $apiParams['where'] = [
        ['email.email', '=', $email],
        ['is_deleted', '=', 0],
    ];
    $apiParams['checkPermissions'] = FALSE;
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
   *
   * @throws AmbiguousResultException
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
