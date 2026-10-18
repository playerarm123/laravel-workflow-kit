<?php

use App\Domain\Shared\Exceptions\DomainValueException;
use App\Domain\Shared\Exceptions\InvalidCalendarPeriodException;
use App\Domain\Shared\ValueObjects\CalendarDate;
use App\Domain\Shared\ValueObjects\CalendarPeriod;

describe('CalendarPeriod', function () {
    describe('from', function () {
        it('holds its first and last day', function () {
            $period = CalendarPeriod::fromStrings('2026-10-01', '2026-10-31');

            expect($period->start->toString())->toBe('2026-10-01')
                ->and($period->end->toString())->toBe('2026-10-31')
                ->and($period->equals(CalendarPeriod::from(CalendarDate::fromString('2026-10-01'), CalendarDate::fromString('2026-10-31'))))->toBeTrue();
        });

        it('holds a single day', function () {
            expect(CalendarPeriod::fromStrings('2026-10-05', '2026-10-05')->days())->toBe(1);
        });

        it('refuses a period that ends before it starts, rather than swap the two', function () {
            try {
                CalendarPeriod::fromStrings('2026-10-31', '2026-10-01');
                $this->fail('expected a reversed period to be refused');
            } catch (InvalidCalendarPeriodException $e) {
                expect($e)->toBeInstanceOf(DomainValueException::class)
                    ->and($e->getCode())->toBe(InvalidCalendarPeriodException::ENDS_BEFORE_START)
                    ->and($e->context())->toBe(['start' => '2026-10-31', 'end' => '2026-10-01']);
            }
        });
    });

    describe('contains', function () {
        it('holds its first and last day, and none outside them', function (string $day, bool $inside) {
            expect(CalendarPeriod::fromStrings('2026-10-01', '2026-10-31')->contains(CalendarDate::fromString($day)))->toBe($inside);
        })->with([
            'the first day' => ['2026-10-01', true],
            'a day inside' => ['2026-10-15', true],
            'the last day' => ['2026-10-31', true],
            'the day before' => ['2026-09-30', false],
            'the day after' => ['2026-11-01', false],
        ]);
    });

    describe('overlaps', function () {
        it('overlaps a period it shares a day with, and not one it only touches', function (string $start, string $end, bool $overlaps) {
            $period = CalendarPeriod::fromStrings('2026-10-01', '2026-10-05');

            expect($period->overlaps(CalendarPeriod::fromStrings($start, $end)))->toBe($overlaps)
                ->and(CalendarPeriod::fromStrings($start, $end)->overlaps($period))->toBe($overlaps);
        })->with([
            'the same days' => ['2026-10-01', '2026-10-05', true],
            'sharing the last day' => ['2026-10-05', '2026-10-09', true],
            'sharing the first day' => ['2026-09-25', '2026-10-01', true],
            'inside it' => ['2026-10-02', '2026-10-03', true],
            'around it' => ['2026-09-01', '2026-10-31', true],
            'the day after' => ['2026-10-06', '2026-10-09', false],
            'the days before' => ['2026-09-01', '2026-09-30', false],
        ]);
    });

    describe('days', function () {
        it('counts both ends', function () {
            expect(CalendarPeriod::fromStrings('2026-10-01', '2026-10-31')->days())->toBe(31)
                ->and(CalendarPeriod::fromStrings('2028-02-01', '2028-02-29')->days())->toBe(29);
        });
    });

    describe('format', function () {
        it('writes the period for a log line', function () {
            expect(CalendarPeriod::fromStrings('2026-10-01', '2026-10-31')->format())->toBe('2026-10-01 – 2026-10-31');
        });
    });
});
