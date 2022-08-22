<?php

namespace Civi\Osdi\ActionNetwork\Mapper\Tag;

use Civi\Osdi\LocalRemotePair;
use Civi\Osdi\Result\Map as MapResult;

class Basic implements \Civi\Osdi\MapperInterface {

  public function mapOneWay(LocalRemotePair $pair): MapResult {
    $result = new MapResult();
    $originObject = $pair->getOriginObject();
    $targetObject = $pair->getTargetObject();

    if ($targetObject->getId() && $pair->isOriginLocal()) {
      if ($targetObject->name->get() !== $originObject->name->get()) {
        return $result->setStatusCode(MapResult::SKIPPED_ALL_CHANGES)
          ->setMessage('Tag was changed in Civi, but tags cannot be altered on Action Network');
      }
    }

    try {
      $targetObject->name->set($originObject->name->get());
    }
    catch (\Throwable $e) {
      return $result->setStatusCode(MapResult::ERROR)
        ->setMessage('Error during mapping: ' . $e->getMessage())
        ->setContext(['exception' => $e]);
    }

    return $result->setStatusCode(MapResult::SUCCESS);
  }

}
