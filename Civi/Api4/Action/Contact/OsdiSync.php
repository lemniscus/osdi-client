<?php
namespace Civi\Api4\Action\Contact;

use Civi\Api4\Generic\BasicBatchAction;

class OsdiSync extends BasicBatchAction {

  private $syncer;

  public function __construct($entityName, $actionName) {
    parent::__construct($entityName, $actionName);
    $this->syncer = new \Civi\Osdi\Syncer();
  }

  protected function doTask($row) {

    $this->syncer->syncContact($row['id']);

    return [];
  }

}
