<?php

use App\Domain\Shared\Ports\Clock;
use App\Infra\Time\SystemClock;
use Carbon\CarbonInterface;

/**
 * The app's zone for one test. Laravel sets PHP's default zone from config('app.timezone') when
 * it boots, so the next test starts on the project's own zone again.
 */
function systemClockIn(string $zone): void
{
    config(['app.timezone' => $zone]);
    date_default_timezone_set($zone);
}

describe('SystemClock', function () {
    it('is the Clock the container hands the domain', function () {
        expect(app(Clock::class))->toBeInstanceOf(SystemClock::class);
    });

    it('reads the time a test travels to, in the app\'s time zone, as a plain DateTimeImmutable', function () {
        systemClockIn('Asia/Bangkok');
        $this->travelTo(new DateTimeImmutable('2026-10-05 17:30:00', new DateTimeZone('UTC')));

        $now = app(Clock::class)->now();

        expect($now)->not->toBeInstanceOf(CarbonInterface::class)
            ->and($now->format(DateTimeInterface::ATOM))->toBe('2026-10-06T00:30:00+07:00');
    });

    it('names the day it is in the app\'s time zone, not UTC\'s', function () {
        systemClockIn('Asia/Bangkok');
        $this->travelTo(new DateTimeImmutable('2026-10-05 17:30:00', new DateTimeZone('UTC')));

        expect(app(Clock::class)->today()->toString())->toBe('2026-10-06');
    });
});
