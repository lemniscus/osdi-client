<?php
namespace Civi\Api4\Action\Contact;

use Civi\Api4\Generic\BasicBatchAction;

class OsdiSync extends BasicBatchAction {

  private $syncer;

  public function __construct($entityName, $actionName) {
    parent::__construct($entityName, $actionName);
    //$this->syncer = new \Civi\Osdi\ActionNetwork\Syncer\Person();
  }

  protected function doTask($row) {

    //$result = $this->syncer->syncContact($row['id']);

    return [];
  }

}
