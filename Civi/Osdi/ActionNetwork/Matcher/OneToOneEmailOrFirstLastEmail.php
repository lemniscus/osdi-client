<?php

namespace Civi\Osdi\ActionNetwork\Matcher;

use Civi\Osdi\ActionNetwork\OsdiPerson;
use Civi\Osdi\Exception\AmbiguousResultException;
use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\MatchResult;
use Civi\Osdi\RemoteSystemInterface;
use Civi\Osdi\ResultCollection;

class OneToOneEmailOrFirstLastEmail {

  protected $system;

  /**
   * OneToOneEmailOrFirstLastEmail constructor.
   */
  public function __construct(RemoteSystemInterface $system) {
    $this->system = $system;
  }

  public function findRemoteMatchForLocalContact(int $id) {
    $api4ContactResult = $this->getContactById($id);
    if ($api4ContactResult->count() === 0) {
      return new MatchResult([], MatchResult::ERROR_INVALID_ID);
    }

    $contactArr = $api4ContactResult->first();
    if (empty($email = $contactArr['email.email'])) {
      return new MatchResult([], MatchResult::NO_MATCH, 'Insufficient data in source contact to match on');
    }

    $civiContactsWithEmail = $this->getCiviContactsBy($email);
    if ($civiContactsWithEmail->count() > 1) {
      return $this->findRemoteMatchByEmailAndName(
        $email,
        $contactArr['first_name'] ?? '',
        $contactArr['last_name'] ?? ''
      );
    }

    $remoteSystemFindResult = $this->system->find('osdi:people', [
      [
        'email',
        'eq',
        $email,
      ],
    ]);
    return $this->makeSingleOrZeroMatchResult($remoteSystemFindResult);
  }

  private function findRemoteMatchByEmailAndName(string $email, string $firstName, string $lastName): MatchResult {
    $civiApi4Result = $this->getCiviContactsBy($email, $firstName, $lastName);
    $countOfCiviContactsWithSameEmailFirstLast = $civiApi4Result->count();
    if ($countOfCiviContactsWithSameEmailFirstLast > 1) {
      return new MatchResult(
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
    return $this->makeSingleOrZeroMatchResult($remoteSystemFindResult);
  }

  public function findLocalMatchForRemotePerson(OsdiPerson $remotePerson) {
    if (empty($email = $remotePerson->getEmailAddress())) {
      return new MatchResult(
        [],
        MatchResult::NO_MATCH,
        'Insufficient data in source contact to match on');
    }

    $civiContactsWithEmail = $this->getCiviContactsBy($email);
    if ($civiContactsWithEmail->count() === 1) {
      return new MatchResult($civiContactsWithEmail->getArrayCopy());
    }

    if ($civiContactsWithEmail->count() === 0) {
      return new MatchResult([], MatchResult::NO_MATCH, 'No match by email');
    }

    if ($civiContactsWithEmail->count() > 1) {
      return $this->findLocalMatchByEmailAndName($remotePerson);
    }
  }

  private function findLocalMatchByEmailAndName(OsdiPerson $remotePerson) {
    $civiApi4Result = $this->getCiviContactsBy(
      $remotePerson->getEmailAddress(),
      $remotePerson->getOriginal('given_name'),
      $remotePerson->getOriginal('family_name')
    );
    $countOfCiviContactsWithSameEmailFirstLast = $civiApi4Result->count();

    if ($countOfCiviContactsWithSameEmailFirstLast > 1) {
      return new MatchResult(
        [],
        MatchResult::ERROR_INDETERMINATE,
        'The email, first name and last name of the source CiviCRM contact are not unique in CiviCRM',
        $civiApi4Result);
    }

    if ($countOfCiviContactsWithSameEmailFirstLast === 0) {
      return new MatchResult(
        [],
        MatchResult::NO_MATCH,
        'No match by email, first name and last name');
    }

    return new MatchResult($civiApi4Result->getArrayCopy());
  }

  /**
   * @throws \API_Exception
   */
  private function getContactById(int $id): \Civi\Api4\Generic\Result {
    return civicrm_api4(
      'Contact',
      'get',
      [
        'select' => [
          'id',
          'first_name',
          'last_name',
          'email.email',
        ],
        'join' => [
          ['Email AS email', FALSE, NULL, ['email.is_primary', '=', 1]],
        ],
        'where' => [
          ['id', '=', $id],
        ],
        'limit' => 2,
        'checkPermissions' => FALSE,
      ]
    );
  }

  private function getCiviContactsBy(
    string $email,
    string $firstName = NULL,
    string $lastName = NULL
  ): \Civi\Api4\Generic\Result {
    $apiParams = [
      'select' => [
        'id',
        'first_name',
        'last_name',
        'email.email',
      ],
      'join' => [
        ['Email AS email', FALSE, NULL, ['email.is_primary', '=', 1]],
      ],
      'where' => [
        ['email.email', '=', $email],
        ['is_deleted', '=', 0],
      ],
      'checkPermissions' => FALSE,
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
   * @throws AmbiguousResultException
   */
  private function makeSingleOrZeroMatchResult(ResultCollection $collection): MatchResult {
    if (($count = $collection->currentCount()) > 1) {
      throw new AmbiguousResultException('At most one match expected, %d returned', $count);
    }
    try {
      return new MatchResult([$collection->first()]);
    }
    catch (EmptyResultException $e) {
      return new MatchResult([], MatchResult::NO_MATCH, 'No match by email, first name and last name');
    }
  }

}
