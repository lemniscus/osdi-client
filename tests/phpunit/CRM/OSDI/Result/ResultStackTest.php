<?php

/**
 * @group headless
 */
class CRM_OSDI_Result_ResultStackTest extends PHPUnit\Framework\TestCase implements
  \Civi\Test\HeadlessInterface,
  \Civi\Test\TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public static function setUpBeforeClass(): void {
  }

  protected function setUp(): void {
  }

  protected function tearDown(): void {
   }

  public static function tearDownAfterClass(): void {
  }

  public function testConstruct() {
    $stack = new Civi\Osdi\Result\ResultStack(new \Civi\Osdi\Result\Sync());
    self::assertEquals(\Civi\Osdi\Result\Sync::class, get_class($stack->last()));
  }

  public function testLast() {
    $stack = new Civi\Osdi\Result\ResultStack();
    self::assertNull($stack->last());

    $stack->push(new \Civi\Osdi\Result\Sync());
    self::assertEquals(\Civi\Osdi\Result\Sync::class, get_class($stack->last()));

    $stack->push(new \Civi\Osdi\Result\Save());
    self::assertEquals(\Civi\Osdi\Result\Save::class, get_class($stack->last()));
  }

  public function testForeach() {
    $testData = [
      0 => new \Civi\Osdi\Result\Sync(),
      1 => new \Civi\Osdi\Result\MatchResult(\Civi\Osdi\Result\MatchResult::ORIGIN_LOCAL)
    ];

    $stack = new Civi\Osdi\Result\ResultStack;
    $stack->push($testData[0]);
    $stack->push($testData[1]);

    self::assertTrue(is_a($stack, Iterator::class));

    $currentIndex = 1;
    foreach ($stack as $i => $result) {
      self::assertEquals($currentIndex, $i);
      self::assertTrue(is_a($result, Civi\Osdi\ResultInterface::class));
      $currentIndex--;
    }
  }

  public function testLastIsError() {
    $syncResultError = new \Civi\Osdi\Result\Sync(
      NULL, NULL, \Civi\Osdi\Result\Sync::ERROR);
    $syncResultSuccess = new \Civi\Osdi\Result\Sync(
      NULL, NULL, \Civi\Osdi\Result\Sync::SUCCESS);
    $stack = new Civi\Osdi\Result\ResultStack();

    $stack->push($syncResultError);
    self::assertTrue($stack->lastIsError());

    $stack->push($syncResultSuccess);
    self::assertFalse($stack->lastIsError());
  }

}
