<?php


namespace Civi\Osdi\ActionNetwork;


use Civi\Osdi\Exception\EmptyResultException;
use Civi\Osdi\RemoteObjectInterface;
use Civi\Osdi\RemoteSystemInterface;
use Jsor\HalClient\HalResource;

class OsdiObject extends \Civi\Osdi\Generic\OsdiObject implements RemoteObjectInterface {

  /**
   * @var string
   */
  protected $namespace = 'action_network';

  /**
   * @var string
   */
  protected $id;

  /**
   * @var string
   */
  protected $type;

  /**
   * @var HalResource|null
   */
  protected $resource;

  /**
   * @var mixed[]
   */
  protected $alteredData = [];

  /**
   * @var string[]
   */
  protected $fieldsToClear = [];

  public function getNamespace(): string {
    return $this->namespace;
  }

  public function getOwnUrl(RemoteSystemInterface $system):string {
    try {
      if ($selfLink = $this->resource->getFirstLink('self')) return $selfLink->getHref();
    } catch (\Throwable $e) {
      try {
        return $this->constructOwnUrl($system);
      } catch (\Throwable $e) {
        throw new EmptyResultException(
            'Could not find or create url for "%s" with type "%s" and id "%s"',
            __CLASS__, $this->getType(), $this->getId());
      }
    }
  }

  protected function extractIdFromResource(?HalResource $resource): ?string {
    if (!$resource) return null;
    $identifiers = $this->resource->getProperty('identifiers');
    if (!$identifiers) return null;
    $prefix = 'action_network:';
    $prefixLength = 15;
    foreach ($identifiers as $identifier) {
      if ($prefix === substr($identifier, 0, $prefixLength)) {
        return substr($identifier, $prefixLength);
      }
    }
  }

  public static function isMultipleValueField(string $name): bool {
    $multipleValueFields = [
        'identifiers',
    ];
    return in_array($name, $multipleValueFields);
  }

  public static function isClearableField(string $fieldName): bool {
    return FALSE;
  }

  /**
   * @param RemoteSystemInterface $system
   * @return string
   * @throws EmptyResultException
   */
  private function constructOwnUrl(RemoteSystemInterface $system): string {
    if (empty($id = $this->getId()))
      throw new EmptyResultException('Cannot calculate a url for an object that has no id');
    return $system->constructUrlFor($this->getType(), $this->getId());
  }
}