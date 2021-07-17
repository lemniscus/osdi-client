<?php

namespace Civi\Osdi;

use Civi\Osdi\Exception\EmptyResultException;
use Jsor\HalClient\HalResource;

class ResultCollection {

  /**
   * @var string
   */
  private $type;

  /**
   * @var array
   */
  private $pages = [];

  /**
   * @var int
   */
  private $resultCount;

  /**
   * @var RemoteSystemInterface
   */
  private $system;

  public function __construct(RemoteSystemInterface $system, string $type, HalResource $resource) {
    $this->system = $system;
    $this->type = $type;
    $this->addPage($resource);
  }

  /**
   * @return \Jsor\HalClient\HalResource[]
   */
  public function toArray(): array {
    foreach ($this->pages as $page) {
      foreach ($page->getResource($this->type) as $resource) {
        $resultArr[] = $this->system->makeOsdiObject($this->type, $resource);
      }
    }
    return $resultArr ?? [];
  }

  public function currentCount(): int {
    return $this->resultCount;
  }

  public function first(): RemoteObjectInterface {
    if (empty($this->pages)) {
      throw new EmptyResultException();
    }
    ksort($this->pages, SORT_NUMERIC);
    $firstPage = reset($this->pages);
    $firstResource = $firstPage->getFirstResource($this->type);
    if ($firstResource === NULL) {
      throw new EmptyResultException();
    }
    return $this->system->makeOsdiObject($this->type, $firstResource);
  }

  public function column(string $key): array {
    /** @var RemoteObjectInterface $remoteObject */
    foreach ($this->toArray() as $remoteObject) {
      $result[] = $remoteObject->getOriginal($key);
    }
    return $result;
  }

  private function addPage(HalResource $pageResource) {
    if (!is_numeric($pageNum = $pageResource->getProperty('page'))) {
      return;
    }
    $this->pages[$pageNum] = $pageResource;
    try {
      $this->resultCount += count($pageResource->getResource($this->type));
    }
    catch (\Throwable $e) {
    }
  }

}
