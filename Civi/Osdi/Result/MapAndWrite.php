<?php

namespace Civi\Osdi\Result;

use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\ResultInterface;

class MapAndWrite extends AbstractResult implements ResultInterface {

  use SimpleErrorTrait;

  const ERROR = 'error';

  const NO_CHANGES_TO_WRITE = 'no changes to write';

  const SAVE_ERROR = 'error during save';

  const SKIPPED_CHANGES = 'skipped writing changes';

  const WROTE_CHANGES = 'wrote changes';

  const WROTE_NEW = 'wrote new record';

  protected ?LocalRemotePair $pairBefore = NULL;

  protected ?Save $saveResult = NULL;

  public function toArray(): array {
    $saveResult = $this->getSaveResult();
    return [
      'type' => $this->getType(),
      'status' => $this->getStatusCode(),
      'message' => $this->getMessage(),
      'pairBefore' => $this->getPairBefore(),
      'saveResult' => $saveResult ? $saveResult->toArray() : NULL,
      'context' => $this->getContextAsArray(),
    ];
  }

  public function getPairBefore(): ?LocalRemotePair {
    return $this->pairBefore;
  }

  public function setPairBefore(?LocalRemotePair $pairBefore): self {
    $this->pairBefore = $pairBefore;
    return $this;
  }

  public function getSaveResult(): ?Save {
    return $this->saveResult;
  }

  public function setSaveResult(?Save $saveResult): self {
    $this->saveResult = $saveResult;
    return $this;
  }

}
