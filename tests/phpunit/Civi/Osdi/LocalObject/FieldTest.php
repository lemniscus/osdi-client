<?php

namespace Civi\Osdi\LocalObject;

use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * @group headless
 */
class FieldTest extends \PHPUnit\Framework\TestCase implements
    HeadlessInterface,
    TransactionalInterface {

  public \Civi\Osdi\LocalObject\Field $field;

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Test::headless()->installMe(__DIR__)->apply();
  }

  public function setUp(): void {
    $this->field = new \Civi\Osdi\LocalObject\Field('test');
    parent::setUp();
  }

  public function tearDown(): void {
    unset($this->field);
    parent::tearDown();
  }

  public function testSetAndGet() {
    $this->field->set('hello world');
    self::assertEquals('hello world', $this->field->get());
  }

  public function testSetReadonlyFieldThrowsError() {
    $field = new \Civi\Osdi\LocalObject\Field('foo', ['readOnly' => TRUE]);
    self::expectException(\Civi\Osdi\Exception\InvalidOperationException::class);
    $field->set('bar');
  }

  public function testLoadAndGet() {
    $this->field->load('hello world');
    self::assertEquals('hello world', $this->field->get());
  }

  public function testLoadAndSetAndGet() {
    $this->field->load('hello world');
    $this->field->set('goodbye');
    self::assertEquals('goodbye', $this->field->get());
  }

  public function testSetValueIsNotAffectedByLoad() {
    $this->field->set('goodbye');
    $this->field->load('hello world');
    self::assertEquals('goodbye', $this->field->get());
  }

  public function testIsNotTouchedWhenLoaded() {
    self::assertFalse($this->field->isTouched());
    $this->field->load('foo');
    self::assertFalse($this->field->isTouched());
  }

  public function testIsTouchedWhenValueIsChanged() {
    self::assertFalse($this->field->isTouched());
    self::assertNotEquals('foo@bar.com', $this->field->get());
    $this->field->set('foo@bar.com');
    self::assertTrue($this->field->isTouched());
  }

  public function testIsTouchedEvenWhenSameValueIsSet() {
    $this->field->load('foo');
    self::assertFalse($this->field->isTouched());
    $this->field->set('foo');
    self::assertTrue($this->field->isTouched());
  }

  public function testIsAltered() {
    $this->field->load('Solange');
    self::assertFalse($this->field->isAltered());
    $this->field->set('Beyonce');
    self::assertTrue($this->field->isAltered());
    $this->field->set('Solange');
    self::assertFalse($this->field->isAltered());

    $this->field->load(NULL);
    $this->field->set(NULL);
    self::assertFalse($this->field->isAltered());
    $this->field->set('');
    self::assertTrue($this->field->isAltered());
    self::assertFalse($this->field->isAlteredLoose());
  }

}
