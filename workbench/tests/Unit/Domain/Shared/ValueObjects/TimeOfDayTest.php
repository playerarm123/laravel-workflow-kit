<?php

use App\Domain\Shared\Exceptions\DomainValueException;
use App\Domain\Shared\Exceptions\InvalidTimeOfDayException;
use App\Domain\Shared\ValueObjects\CalendarDate;
use App\Domain\Shared\ValueObjects\TimeOfDay;

describe('TimeOfDay', function () {
    describe('fromString', function () {
        it('reads a time as a form posts it or a time column returns it', function (string $input, int $minutes, string $sent) {
            $time = TimeOfDay::fromString($input);

            expect($time->minutes)->toBe($minutes)
                ->and($time->toString())->toBe($sent);
        })->with([
            'a form' => ['09:30', 570, '09:30'],
            'a time column' => ['09:30:00', 570, '09:30'],
            'midnight' => ['00:00', 0, '00:00'],
            'the last minute' => ['23:59', 1439, '23:59'],
        ]);

        it('refuses any other shape, or seconds it would drop, as an invalid value', function (string $input) {
            try {
                TimeOfDay::fromString($input);
                $this->fail("expected '{$input}' to be refused");
            } catch (InvalidTimeOfDayException $e) {
                expect($e)->toBeInstanceOf(DomainValueException::class)
                    ->and($e->getCode())->toBe(InvalidTimeOfDayException::MALFORMED)
                    ->and($e->context())->toBe(['time' => $input]);
            }
        })->with([
            'empty' => [''],
            'hour 24' => ['24:00'],
            'minute 60' => ['09:60'],
            'no leading zero' => ['9:30'],
            'the 12-hour clock' => ['09:30 PM'],
            'seconds' => ['09:30:15'],
            'a day before it' => ['2026-10-05 09:30'],
        ]);
    });

    describe('of and fromMinutes', function () {
        it('builds a time from its hour and minute, or from the minutes since midnight', function () {
            expect(TimeOfDay::of(18, 5)->toString())->toBe('18:05')
                ->and(TimeOfDay::fromMinutes(1085)->equals(TimeOfDay::of(18, 5)))->toBeTrue()
                ->and([TimeOfDay::of(18, 5)->hour(), TimeOfDay::of(18, 5)->minute()])->toBe([18, 5])
                ->and(TimeOfDay::midnight()->minutes)->toBe(0);
        });

        it('refuses an hour or a minute the clock does not have', function () {
            try {
                TimeOfDay::of(24, 0);
                $this->fail('expected hour 24 to be refused');
            } catch (InvalidTimeOfDayException $e) {
                expect($e->getCode())->toBe(InvalidTimeOfDayException::NOT_ON_THE_CLOCK)
                    ->and($e->context())->toBe(['hour' => 24, 'minute' => 0]);
            }
        });

        it('refuses minutes outside one day', function (int $minutes) {
            expect(fn () => TimeOfDay::fromMinutes($minutes))
                ->toThrow(fn (InvalidTimeOfDayException $e) => expect($e->getCode())->toBe(InvalidTimeOfDayException::OUT_OF_DAY));
        })->with([-1, 1440]);
    });

    describe('on', function () {
        it('is the moment the time falls at on a day in a zone', function () {
            $moment = TimeOfDay::fromString('18:00')->on(CalendarDate::fromString('2026-10-05'), new DateTimeZone('Asia/Bangkok'));

            expect($moment->format(DateTimeInterface::ATOM))->toBe('2026-10-05T18:00:00+07:00');
        });
    });

    describe('comparisons', function () {
        it('compares by the minutes since midnight', function () {
            $opens = TimeOfDay::fromString('09:00');

            expect($opens->equals(TimeOfDay::of(9, 0)))->toBeTrue()
                ->and($opens->isBefore(TimeOfDay::fromString('09:01')))->toBeTrue()
                ->and($opens->isAfter(TimeOfDay::midnight()))->toBeTrue()
                ->and($opens->isBefore($opens))->toBeFalse();
        });
    });
});
