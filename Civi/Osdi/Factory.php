<?php

namespace Civi\Osdi;

use Civi\Osdi\Exception\InvalidArgumentException;

class Factory {

  public static array $registry = [
    'LocalObject' => [
      'Person' => LocalObject\PersonBasic::class,
    ],
    'OsdiObject' => [
      'osdi:people' => ActionNetwork\Object\Person::class,
      'osdi:tags' => ActionNetwork\Object\Tag::class,
      'osdi:taggings' => ActionNetwork\Object\Tagging::class,
    ],
    'Mapper' => [
      'Person' => ActionNetwork\Mapper\PersonBasic::class,
      'Tag' => ActionNetwork\Mapper\TagBasic::class,
      'Tagging' => ActionNetwork\Mapper\TaggingBasic::class,
    ],
    'Matcher' => [
      'Person' => ActionNetwork\Matcher\Person\UniqueEmailOrFirstLastEmail::class,
      'Tag' => ActionNetwork\Matcher\TagBasic::class,
      'Tagging' => ActionNetwork\Matcher\TaggingBasic::class,
    ],
    'SingleSyncer' => [
      'Person' => ActionNetwork\SingleSyncer\Person\PersonBasic::class,
      'Tag' => ActionNetwork\SingleSyncer\TagBasic::class,
      'Tagging' => ActionNetwork\SingleSyncer\TaggingBasic::class,
    ],
  ];

  #[\ReturnTypeWillChange]
  public static function make(string $category, string $key, ...$constructorParams) {
    $class = self::$registry[$category][$key] ?? NULL;
    if (is_null($class)) {
      throw new InvalidArgumentException();
    }
    return new $class(...$constructorParams);
  }
  
  public static function register(string $category, string $key, string $class) {
    self::$registry[$category][$key] = $class;
  }

}
