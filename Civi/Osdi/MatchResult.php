<?php

namespace Civi\Osdi;

use Civi\Osdi\Exception\EmptyResultException;

class MatchResult {

  const ERROR_INDETERMINATE = 'provided criteria do not uniquely identify the source contact';

  const ERROR_INVALID_ID = 'invalid contact id';

  const NO_MATCH = 'no match found';

  /**
   * @var array[RemoteObjectInterface]
   */
  protected $matches;

  /**
   * @var string
   */
  protected $statusCode;

  /**
   * @var string
   */
  protected $message;

  /**
   * @var mixed
   */
  protected $context;

  public function __construct(array $matches, $errorType = NULL, $errorReason = NULL, $context = NULL) {
    $this->matches = $matches;
    $this->statusCode = $errorType;
    $this->message = $errorReason;
    $this->context = $context;
  }

  /**
   * @return array [RemoteObjectInterface]
   */
  public function matches(): array {
    return $this->matches;
  }

  /**
   * @return RemoteObjectInterface|array
   * @throws EmptyResultException
   */
  public function first() {
    if (empty($this->matches[0])) {
      throw new EmptyResultException();
    }
    return $this->matches[0];
  }

  public function count(): int {
    return count($this->matches);
  }

  public function isError(): bool {
    return in_array(
      $this->statusCode,
      [
        self::ERROR_INDETERMINATE,
        self::ERROR_INVALID_ID,
      ]
    );
  }

  public function status(): ?string {
    return $this->statusCode;
  }

  public function context() {
    return $this->context;
  }

}
