<?php

namespace Civi\Osdi\ActionNetwork;

use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\RemoteObjectInterface;

class ResultCollection extends \Civi\Osdi\ResultCollection {

  /**
   * @param \Jsor\HalClient\HalResource[] $resources
   */
  protected function filteredCount(array $resources): int {
    if ('osdi:people' !== $this->type) {
      return count($resources);
    }
    $n = 0;
    foreach ($resources as $personResource) {
      if ($this->isSubscribedByEmailOrPhone($personResource)) {
        $n++;
      }
    }
    return $n;
  }

  public function filteredFirst(): RemoteObjectInterface {
    if ('osdi:people' !== $this->type) {
      return parent::filteredFirst();
    }

    if (empty($this->pages)) {
      throw new EmptyResultException();
    }

    ksort($this->pages, SORT_NUMERIC);

    foreach ($this->pages as $page) {
      $resources = $page->getResource($this->type);

      foreach ($resources as $resource) {
        if ($this->isSubscribedByEmailOrPhone($resource)) {
          return $this->system->makeOsdiObject($this->type, $resource);
        }
      }
    }

    throw new EmptyResultException();
  }

  protected function isSubscribedByEmailOrPhone(\Jsor\HalClient\HalResource $personResource): bool {
    $emailStatus = $personResource->getProperty('email_addresses')[0]['status'] ?? NULL;
    $phoneStatus = $personResource->getProperty('phone_numbers')[0]['status'] ?? NULL;
    return ($emailStatus === 'subscribed' || $phoneStatus === 'subscribed');
  }

}
