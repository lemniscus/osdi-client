<?php
namespace Civi\Osdi;

use Civi\Osdi\RemoteObjectInterface;

class LinkageTracker {

  private $remoteSystemId;

  /**
   * CRM_OSDI_LinkageTracker constructor.
   */
  public function __construct($remoteSystemId) {
    $this->remoteSystemId = $remoteSystemId;
  }

  public static function syncOriginPseudoConstant(): array {
    return [
        0 => 'local',
        1 => 'remote'
    ];
  }

}