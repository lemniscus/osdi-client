<?php

namespace Civi\Osdi\Result;

use Civi\Osdi\ResultInterface;

class MapAndWrite extends AbstractResult implements ResultInterface {

  use SimpleErrorTrait;

  const ERROR = 'error';

  const NO_CHANGES_TO_WRITE = 'no changes to write';

  const SAVE_ERROR = 'error during save';

  const SKIPPED_CHANGES = 'skipped writing changes';

  const WROTE_CHANGES = 'wrote changes';

  const WROTE_NEW = 'wrote new record';

  protected ?Save $saveResult = NULL;

  public function toArray(): array {
    $saveResult = $this->getSaveResult();
    return [
      'type' => $this->getType(),
      'status' => $this->getStatusCode(),
      'message' => $this->getMessage(),
      'saveResult' => $saveResult ? $saveResult->toArray() : NULL,
      'context' => $this->getContextAsArray(),
    ];
  }

  public function getSaveResult(): ?Save {
    return $this->saveResult;
  }

  public function setSaveResult(?Save $saveResult): MapAndWrite {
    $this->saveResult = $saveResult;
    return $this;
  }

}
