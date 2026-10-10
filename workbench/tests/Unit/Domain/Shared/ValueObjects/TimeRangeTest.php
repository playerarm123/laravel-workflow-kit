<?php

use App\Domain\Shared\ValueObjects\TimeOfDay;
use App\Domain\Shared\ValueObjects\TimeRange;

describe('TimeRange', function () {
    describe('from', function () {
        it('creates a time range', function () {
            $start = TimeOfDay::from(540);
            $end = TimeOfDay::from(1080);

            $range = TimeRange::from($start, $end);

            expect($range->start)->toBe($start)
                ->and($range->end)->toBe($end);
        });
    });

    describe('contains', function () {
        it('returns true when time is inside the range', function () {
            $range = TimeRange::from(
                TimeOfDay::from(540),  // 09:00
                TimeOfDay::from(1080), // 18:00
            );

            expect($range->contains(
                TimeOfDay::from(720), // 12:00
            ))->toBeTrue();
        });

        it('returns true when time is at the start of the range', function () {
            $range = TimeRange::from(
                TimeOfDay::from(540),
                TimeOfDay::from(1080),
            );

            expect($range->contains(
                TimeOfDay::from(540),
            ))->toBeTrue();
        });

        it('returns true when time is at the end of the range', function () {
            $range = TimeRange::from(
                TimeOfDay::from(540),
                TimeOfDay::from(1080),
            );

            expect($range->contains(
                TimeOfDay::from(1080),
            ))->toBeTrue();
        });

        it('returns false when time is before the range', function () {
            $range = TimeRange::from(
                TimeOfDay::from(540),
                TimeOfDay::from(1080),
            );

            expect($range->contains(
                TimeOfDay::from(539),
            ))->toBeFalse();
        });

        it('returns false when time is after the range', function () {
            $range = TimeRange::from(
                TimeOfDay::from(540),
                TimeOfDay::from(1080),
            );

            expect($range->contains(
                TimeOfDay::from(1081),
            ))->toBeFalse();
        });
    });
});
