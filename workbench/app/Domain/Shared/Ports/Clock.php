<?php

namespace App\Domain\Shared\Ports;

use App\Domain\Shared\ValueObjects\CalendarDate;
use DateTimeImmutable;

/**
 * The domain's way to ask what time it is (dates.md). The Domain layer cannot touch Illuminate,
 * so it asks this interface instead of calling now(), and a test moves the clock with
 * `$this->travelTo()` instead of waiting.
 */
interface Clock
{
    /** The current moment, in the app's time zone. */
    public function now(): DateTimeImmutable;

    /** The day it is now in the app's time zone: the user's today, not UTC's. */
    public function today(): CalendarDate;
}
