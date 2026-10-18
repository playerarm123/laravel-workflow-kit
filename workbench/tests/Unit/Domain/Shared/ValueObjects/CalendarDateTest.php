<?php

use App\Domain\Shared\Enums\Weekday;
use App\Domain\Shared\Exceptions\DomainValueException;
use App\Domain\Shared\Exceptions\InvalidCalendarDateException;
use App\Domain\Shared\ValueObjects\CalendarDate;

describe('CalendarDate', function () {
    describe('fromString', function () {
        it('reads a day written as Y-m-d, and sends it back the same', function () {
            $day = CalendarDate::fromString('2026-10-05');

            expect($day->toString())->toBe('2026-10-05')
                ->and([$day->year(), $day->month(), $day->day()])->toBe([2026, 10, 5]);
        });

        it('reads a leap day in a leap year', function () {
            expect(CalendarDate::fromString('2028-02-29')->toString())->toBe('2028-02-29');
        });

        it('refuses a day the calendar does not have, or any other shape, as an invalid value', function (string $input) {
            try {
                CalendarDate::fromString($input);
                $this->fail("expected '{$input}' to be refused");
            } catch (InvalidCalendarDateException $e) {
                expect($e)->toBeInstanceOf(DomainValueException::class)
                    ->and($e->getCode())->toBe(InvalidCalendarDateException::MALFORMED)
                    ->and($e->context())->toBe(['date' => $input]);
            }
        })->with([
            'empty' => [''],
            'February 30' => ['2026-02-30'],
            'a leap day in a common year' => ['2026-02-29'],
            'month 13' => ['2026-13-01'],
            'no leading zeros' => ['2026-2-5'],
            'another order' => ['05/10/2026'],
            'a time after it' => ['2026-10-05 00:00:00'],
            'spaces' => [' 2026-10-05'],
        ]);
    });

    describe('of', function () {
        it('builds a day from its parts', function () {
            expect(CalendarDate::of(2026, 1, 9)->toString())->toBe('2026-01-09');
        });

        it('refuses parts the calendar does not have', function () {
            try {
                CalendarDate::of(2026, 4, 31);
                $this->fail('expected April 31 to be refused');
            } catch (InvalidCalendarDateException $e) {
                expect($e->getCode())->toBe(InvalidCalendarDateException::NOT_ON_THE_CALENDAR)
                    ->and($e->context())->toBe(['year' => 2026, 'month' => 4, 'day' => 31]);
            }
        });
    });

    describe('fromDateTime', function () {
        it('takes the day a moment falls on in the moment\'s own time zone', function () {
            $moment = new DateTimeImmutable('2026-10-05 23:30:00', new DateTimeZone('UTC'));

            expect(CalendarDate::fromDateTime($moment)->toString())->toBe('2026-10-05')
                ->and(CalendarDate::fromDateTime($moment->setTimezone(new DateTimeZone('Asia/Bangkok')))->toString())->toBe('2026-10-06');
        });
    });

    describe('weekday', function () {
        it('names the weekday, valued as ISO numbers it, Monday 1 to Sunday 7', function () {
            expect(CalendarDate::fromString('2026-10-05')->weekday())->toBe(Weekday::Monday)
                ->and(CalendarDate::fromString('2026-10-11')->weekday())->toBe(Weekday::Sunday)
                ->and(CalendarDate::fromString('2026-10-11')->weekday()->value)->toBe(7);
        });
    });

    describe('addDays', function () {
        it('moves across a month and a year, forward and back', function () {
            expect(CalendarDate::fromString('2026-12-31')->addDays(1)->toString())->toBe('2027-01-01')
                ->and(CalendarDate::fromString('2026-03-01')->addDays(-1)->toString())->toBe('2026-02-28')
                ->and(CalendarDate::fromString('2026-10-05')->addDays(0)->toString())->toBe('2026-10-05');
        });
    });

    describe('addMonths', function () {
        it('keeps the day of the month', function () {
            expect(CalendarDate::fromString('2026-01-15')->addMonths(1)->toString())->toBe('2026-02-15')
                ->and(CalendarDate::fromString('2026-01-15')->addMonths(-2)->toString())->toBe('2025-11-15')
                ->and(CalendarDate::fromString('2026-11-15')->addMonths(14)->toString())->toBe('2028-01-15');
        });

        it('takes the last day of a month too short for the day, never one in the month after', function (string $from, int $months, string $to) {
            expect(CalendarDate::fromString($from)->addMonths($months)->toString())->toBe($to);
        })->with([
            'January 31 to February' => ['2026-01-31', 1, '2026-02-28'],
            'January 31 to a leap February' => ['2028-01-31', 1, '2028-02-29'],
            'March 31 back to February' => ['2026-03-31', -1, '2026-02-28'],
            'a leap day a year on' => ['2028-02-29', 12, '2029-02-28'],
            'August 31 to September' => ['2026-08-31', 1, '2026-09-30'],
        ]);
    });

    describe('daysUntil', function () {
        it('counts the days to another day, negative when it is earlier', function () {
            $day = CalendarDate::fromString('2026-10-05');

            expect($day->daysUntil(CalendarDate::fromString('2026-10-12')))->toBe(7)
                ->and($day->daysUntil(CalendarDate::fromString('2026-09-30')))->toBe(-5)
                ->and($day->daysUntil($day))->toBe(0)
                ->and(CalendarDate::fromString('2026-01-01')->daysUntil(CalendarDate::fromString('2027-01-01')))->toBe(365);
        });

        it('counts whole days across a clock change', function () {
            expect(CalendarDate::fromString('2026-03-28')->daysUntil(CalendarDate::fromString('2026-03-30')))->toBe(2);
        });
    });

    describe('startsIn', function () {
        it('is midnight of the day in the zone it is asked for', function () {
            $start = CalendarDate::fromString('2026-10-05')->startsIn(new DateTimeZone('Asia/Bangkok'));

            expect($start->format(DateTimeInterface::ATOM))->toBe('2026-10-05T00:00:00+07:00');
        });
    });

    describe('comparisons', function () {
        it('compares by day', function () {
            $day = CalendarDate::fromString('2026-10-05');

            expect($day->equals(CalendarDate::of(2026, 10, 5)))->toBeTrue()
                ->and($day->isBefore(CalendarDate::fromString('2026-10-06')))->toBeTrue()
                ->and($day->isAfter(CalendarDate::fromString('2025-12-31')))->toBeTrue()
                ->and($day->isBefore($day))->toBeFalse()
                ->and($day->isAfter($day))->toBeFalse();
        });
    });
});
