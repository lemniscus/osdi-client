<?php

namespace Civi\Osdi;

use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\Exception\InvalidArgumentException;
use Jsor\HalClient\HalLink;
use Jsor\HalClient\HalResource;

class RemoteFindResult implements \Iterator {

  protected string $type;

  /**
   * @var \Jsor\HalClient\HalResource[]
   */
  protected array $pages = [];

  /**
   * @var \Jsor\HalClient\HalResource[]
   */
  protected array $currentPageContents = [];

  protected int $currentPageIndex = 1;

  protected int $currentItemIndex = 0;

  protected int $resultCountRaw = 0;

  protected int $resultCountFiltered = 0;

  protected RemoteSystemInterface $system;

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
      $resources = $pageResource->getResource($this->type);
      $this->resultCountRaw += count($resources);
      $this->resultCountFiltered += $this->filteredCount($resources);
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
    $currentPage = $this->pages[$this->currentPageIndex];

    try {
      $nextLink = $currentPage->getFirstLink('next');
    }
    catch (\Jsor\HalClient\Exception\InvalidArgumentException $e) {
      return FALSE;
    }

    // sometimes the "next page" is actually the same page, ad infinitum. bug reported by email to AN, 20222-10-07
    if ($currentPage->hasProperty('total_records')) {
      if ($this->rawCurrentCount() >= $currentPage->getProperty('total_records')) {
        return FALSE;
      }
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

  public function loadAll(): self {
    $savedPageIndex = $this->currentPageIndex;
    $oldItemCount = NULL;

    while ($oldItemCount !== $this->rawCurrentCount()) {
      $oldItemCount = $this->rawCurrentCount();
      $this->loadNextPage();
      $this->currentPageIndex++;
    }

    $this->currentPageIndex = $savedPageIndex;
    return $this;
  }

}
