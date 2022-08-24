<?php

namespace Civi\Osdi;

use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\Exception\InvalidArgumentException;
use Jsor\HalClient\HalLink;
use Jsor\HalClient\HalResource;

class RemoteFindResult implements \Iterator {
  /**
   * @var string
   */
  protected $type;

  /**
   * @var \Jsor\HalClient\HalResource[]
   */
  protected $pages = [];

  /**
   * @var \Jsor\HalClient\HalResource[]
   */
  protected $currentPageContents = [];

  protected int $currentPageIndex = 1;

  protected int $currentItemIndex = 0;

  /**
   * @var int
   */
  protected $resultCountRaw = 0;

  /**
   * @var int
   */
  protected $resultCountFiltered = 0;

  /**
   * @var RemoteSystemInterface
   */
  protected $system;

  public function __construct(RemoteSystemInterface $system, string $type, HalLink $queryLink) {
    $this->system = $system;
    $this->type = $type;
    $resource = $queryLink->get();
    $this->addPage($resource);
  }

  public function current() {
    $halResource = $this->currentPageContents[$this->currentItemIndex];
    return $this->system->makeOsdiObject($this->type, $halResource);
  }

  public function next() {
    $this->currentItemIndex++;

    if ($this->valid()) {
      return;
    }

    if (array_key_exists($this->currentPageIndex + 1, $this->pages)
      || $this->loadNextPage()) {
      $this->currentPageIndex++;
      $this->currentItemIndex = 0;
      $this->loadCurrentPageContents();
    }
  }

  public function key() {
    return [$this->currentPageIndex, $this->currentItemIndex];
  }

  public function valid() {
    return array_key_exists($this->currentItemIndex, $this->currentPageContents);
  }

  public function rewind() {
    $this->currentPageIndex = 1;
    $this->currentItemIndex = 0;
    $this->loadCurrentPageContents();
  }

  protected function addPage(HalResource $pageResource): int {
    if (!is_numeric($pageNum = $pageResource->getProperty('page'))) {
      throw new InvalidArgumentException();
    }
    $this->pages[$pageNum] = $pageResource;
    ksort($this->pages, SORT_NUMERIC);
    try {
      $this->resultCountRaw += count($pageResource->getResource($this->type));
      $this->resultCountFiltered += $this->filteredCount($pageResource->getResource($this->type));
    }
    catch (\Throwable $e) {
    }
    return $pageNum;
  }

  /**
   * @return \Civi\Osdi\RemoteObjectInterface[]
   */
  public function toArray(): array {
    foreach ($this->pages as $page) {
      foreach ($page->getResource($this->type) as $resource) {
        $resultArr[] = $this->system->makeOsdiObject($this->type, $resource);
      }
    }
    return $resultArr ?? [];
  }

  public function rawCurrentCount(): int {
    return $this->resultCountRaw;
  }

  public function filteredCurrentCount(): int {
    return $this->resultCountFiltered;
  }

  public function rawFirst(): RemoteObjectInterface {
    if (empty($this->pages)) {
      throw new EmptyResultException();
    }
    $firstPage = reset($this->pages);
    $firstResource = $firstPage->getFirstResource($this->type);
    if ($firstResource === NULL) {
      throw new EmptyResultException();
    }
    return $this->system->makeOsdiObject($this->type, $firstResource);
  }

  public function filteredFirst() {
    return $this->rawFirst();
  }

  public function column(string $key): array {
    /** @var RemoteObjectInterface $remoteObject */
    foreach ($this->toArray() as $remoteObject) {
      $result[] = $remoteObject->$key->getOriginal();
    }
    return $result;
  }

  protected function filteredCount(array $resources): int {
    return count($resources);
  }

  protected function loadNextPage(): bool {
    try {
      $nextLink = $this->pages[$this->currentPageIndex]->getFirstLink('next');
    }
    catch (\Jsor\HalClient\Exception\InvalidArgumentException $e) {
      return FALSE;
    }

    $pageNum = $this->addPage($nextLink->get());
    if ($pageNum !== $this->currentPageIndex + 1) {
      throw new \Exception('Error loading results from Action Network');
    }

    return TRUE;
  }

  protected function loadCurrentPageContents(): void {
    try {
      $this->currentPageContents = $this->pages[$this->currentPageIndex]->getResource($this->type);
    }
    catch (\Throwable $e) {
      $this->currentPageContents = [];
    }
  }

  public function loadAll() {
    $savedPageIndex = $this->currentPageIndex;
    $oldItemCount = NULL;

    while ($oldItemCount !== $this->rawCurrentCount()) {
      $oldItemCount = $this->rawCurrentCount();
      $this->loadNextPage();
      $this->currentPageIndex++;
    }

    $this->currentPageIndex = $savedPageIndex;
  }

}