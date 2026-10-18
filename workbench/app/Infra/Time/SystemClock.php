<?php

namespace App\Infra\Time;

use App\Domain\Shared\Ports\Clock;
use App\Domain\Shared\ValueObjects\CalendarDate;
use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Override;

/**
 * The Clock the app runs on: Laravel's own, in `config('app.timezone')`, so the time a test
 * fixes with `$this->travelTo()` or `Date::setTestNow()` is the time the domain reads.
 */
class SystemClock implements Clock
{
    #[Override]
    public function now(): DateTimeImmutable
    {
        return Date::now()->toDateTimeImmutable();
    }

    #[Override]
    public function today(): CalendarDate
    {
        return CalendarDate::fromDateTime($this->now());
    }
}
