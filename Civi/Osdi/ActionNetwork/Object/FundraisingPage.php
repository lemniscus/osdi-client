<?php

namespace Civi\Osdi\ActionNetwork\Object;

use Civi\Osdi\ActionNetwork\RemoteFindResult;

class FundraisingPage extends AbstractRemoteObject implements \Civi\Osdi\RemoteObjectInterface {

  public Field $identifiers;
  public Field $createdDate;
  public Field $modifiedDate;
  public Field $name;
  public Field $originSystem;
  public Field $title;
  public Field $browserUrl;

  protected function getFieldMetadata() {
    return [
      'identifiers' => ['path' => ['identifiers'], 'appendOnly' => TRUE],
      'createdDate' => ['path' => ['created_date'], 'readOnly' => TRUE],
      'modifiedDate' => ['path' => ['modified_date'], 'readOnly' => TRUE],
      'name' => ['path' => ['name'], 'createOnly' => TRUE],
      'originSystem' => ['path' => ['origin_system'], 'createOnly' => TRUE],
      'title' => ['path' => ['title']],
      'browserUrl' => ['path' => ['browser_url'], 'readOnly' => TRUE],
    ];
  }

  public static function getType(): string {
    return 'osdi:fundraising_pages';
  }

  public function getUrlForCreate(): string {
    return 'https://actionnetwork.org/api/v2/fundraising_pages';
  }

  public function getDonations(): RemoteFindResult {
    $pageUrl = $this->getUrlForRead();
    $donationsLink = $this->_system->linkify("$pageUrl/donations");
    return new RemoteFindResult($this->_system, 'osdi:donations', $donationsLink);
  }

}

