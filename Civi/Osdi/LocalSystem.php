<?php

namespace Civi\Osdi;

class LocalSystem {

  protected ?string $localUtcOffset = NULL;

  public function convertFromLocalizedDateTimeString(string $dateTimeString): \DateTime {
    return new \DateTime($dateTimeString, new \DateTimeZone($this->getLocalUtcOffset()));
  }

  public function convertToLocalizedDateTimeString(\DateTime $dateTime) {
    $newDateTime = clone $dateTime;
    $newDateTime->setTimezone(new \DateTimeZone($this->getLocalUtcOffset()));
    return $newDateTime->format('Y-m-d H:i:s');
  }

  public function getLocalUtcOffset(): ?string {
    if ($this->localUtcOffset === NULL) {
      $this->localUtcOffset = \Civi::settings()->get('osdiClient.localUtcOffset');
      if (!$this->localUtcOffset) {
        throw new \Civi\Osdi\Exception\InvalidArgumentException(
          '"osdiClient.localUtcOffset" setting must be set.'
          . 'Examples: CDT, -5:00.');
      }
    }
    return $this->localUtcOffset;
  }

}
