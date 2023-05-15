<?php

namespace Civi\Osdi\ActionNetwork;

use Civi\Osdi\ActionNetwork;
use Civi\Osdi\ActionNetwork\Object\Tag;
use OsdiClient\ActionNetwork\TestUtils;
use PHPUnit;

/**
 * @group headless
 */
class RemoteSystemTest extends PHPUnit\Framework\TestCase implements
    \Civi\Test\HeadlessInterface,
    \Civi\Test\TransactionalInterface {

  private static ActionNetwork\RemoteSystem $system;

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  protected function setUp(): void {
    self::$system = TestUtils::createRemoteSystem();
    parent::setUp();
  }

  public function testFindAll() {
    $s = self::$system;
    
    $tag1 = new Tag($s);
    $tag1->name->set('Test findAll 1');
    $tag1->save();

    $tag2 = new Tag($s);
    $tag2->name->set('Test findAll 2');
    $tag2->save();

    $ourTagIds = [$tag1->getId(), $tag2->getId()];

    $startTime = time();
    $elapsedTime = 0;

    for ($i = 0; $i < 5; $i++) {
      $collection = $s->findAll('osdi:tags');
      $ourTestTagsFound = [];
      foreach ($collection as $fetchedTag) {
        if (in_array($fetchedTag->getId(), $ourTagIds)) {
          $ourTestTagsFound[$fetchedTag->getId()] = TRUE;
          if (count($ourTestTagsFound) == 2) {
            break 2;
          }
        }
      }

      $elapsedTime = time() - $startTime;
      if ($i > 0 && $elapsedTime > 4) {
        break;
      }

      sleep(1);
    }

    self::assertCount(2, $ourTestTagsFound,
      "Failed to find AN objects after trying for $elapsedTime seconds");
  }

}
