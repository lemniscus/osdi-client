<?php

use Civi\Osdi\Exception\AmbiguousResultException;
use Civi\Osdi\Exception\EmptyResultException;
use CRM_Osdi_ExtensionUtil as E;
use Civi\Osdi\Generic\OsdiPerson;
use Jsor\HalClient\HalClient;
use Jsor\HalClient\HalLink;
use Jsor\HalClient\HalResource;

class CRM_OSDI_BAO_RemoteSystem extends CRM_OSDI_DAO_RemoteSystem {
  /**
   * @var HalClient
   */
  private $client;

  // moved to \Civi\Osdi\RemoteSystem
  public function getClient(): HalClient {
    if (empty($this->client)) {
      $client = new HalClient($this->entry_point);
      $this->client = $client->withHeader('OSDI-API-Token', $this->api_token);
    }
    return $this->client;
  }
}
