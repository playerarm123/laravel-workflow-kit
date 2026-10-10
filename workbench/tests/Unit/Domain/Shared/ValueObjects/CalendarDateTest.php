<?php

use App\Domain\Shared\Exceptions\DomainValueException;
use App\Domain\Shared\Exceptions\InvalidCalendarDateException;
use App\Domain\Shared\ValueObjects\CalendarDate;

describe('CalendarDate', function () {
    describe('from()', function () {
        it('creates from a valid ISO date', function () {
            $date = CalendarDate::from('2026-08-20');

            expect($date->value())->toBe('2026-08-20');
        });

        it('accepts a leap day on a leap year', function () {
            expect(CalendarDate::from('2024-02-29')->value())->toBe('2024-02-29');
        });

        it('rejects a leap day on a non-leap year', function () {
            expect(fn () => CalendarDate::from('2026-02-29'))
                ->toThrow(InvalidCalendarDateException::class);
        });

        it('rejects a day that overflows into the next month', function () {
            expect(fn () => CalendarDate::from('2026-04-31'))
                ->toThrow(InvalidCalendarDateException::class);
        });

        it('rejects a date without zero padding', function () {
            expect(fn () => CalendarDate::from('2026-8-20'))
                ->toThrow(InvalidCalendarDateException::class);
        });

        it('rejects malformed input', function (string $value) {
            expect(fn () => CalendarDate::from($value))
                ->toThrow(InvalidCalendarDateException::class);
        })->with([
            '',
            '20260820',
            '2026/08/20',
            '20-08-2026',
            '2026-08-20 10:00:00',
            ' 2026-08-20',
            '2026-08-20 ',
            'not-a-date',
            '2026-13-01',
            '2026-00-10',
            '2026-08-00',
        ]);

        it('refuses as an invalid value that carries the offending date', function () {
            try {
                CalendarDate::from('2026-13-01');
                $this->fail('expected a malformed date to be refused');
            } catch (InvalidCalendarDateException $e) {
                expect($e)->toBeInstanceOf(DomainValueException::class)
                    ->and($e->getCode())->toBe(InvalidCalendarDateException::MALFORMED)
                    ->and($e->context())->toBe(['date' => '2026-13-01']);
            }
        });

        it('ignores the current time of day when parsing', function () {
            expect(CalendarDate::from('2026-08-20')->value())->toBe('2026-08-20');
        });
    });

    describe('fromDateTimeImmutable()', function () {
        it('takes the calendar day out of a date time', function () {
            $date = CalendarDate::fromDateTimeImmutable(new DateTimeImmutable('2026-08-20 13:45:07'));

            expect($date->value())->toBe('2026-08-20');
        });

        it('drops the time of day entirely', function (string $time) {
            expect(CalendarDate::fromDateTimeImmutable(new DateTimeImmutable("2026-08-20 {$time}"))->value())
                ->toBe('2026-08-20');
        })->with([
            'midnight' => ['00:00:00'],
            'one second past midnight' => ['00:00:01'],
            'midday' => ['12:00:00'],
            'last second of the day' => ['23:59:59'],
        ]);

        it('zero pads a single digit month and day', function () {
            expect(CalendarDate::fromDateTimeImmutable(new DateTimeImmutable('2026-01-05 10:00:00'))->value())
                ->toBe('2026-01-05');
        });

        it('keeps a leap day intact', function () {
            expect(CalendarDate::fromDateTimeImmutable(new DateTimeImmutable('2024-02-29 08:00:00'))->value())
                ->toBe('2024-02-29');
        });

        it('reads the day in the date time own timezone, not the system one', function () {
            $bangkok = new DateTimeImmutable('2026-08-21 06:00:00', new DateTimeZone('Asia/Bangkok'));

            expect(CalendarDate::fromDateTimeImmutable($bangkok)->value())->toBe('2026-08-21')
                ->and(CalendarDate::fromDateTimeImmutable($bangkok->setTimezone(new DateTimeZone('UTC')))->value())
                ->toBe('2026-08-20');
        });

        it('round trips through from() without drifting', function () {
            $date = CalendarDate::fromDateTimeImmutable(new DateTimeImmutable('2026-12-31 23:59:59'));

            expect($date->equals(CalendarDate::from('2026-12-31')))->toBeTrue();
        });
    });

    describe('equals()', function () {
        it('returns true for the same date', function () {
            expect(CalendarDate::from('2026-08-20')->equals(CalendarDate::from('2026-08-20')))
                ->toBeTrue();
        });

        it('returns false for a different date', function () {
            expect(CalendarDate::from('2026-08-20')->equals(CalendarDate::from('2026-08-21')))
                ->toBeFalse();
        });
    });

    describe('isBefore()', function () {
        it('returns true when earlier', function () {
            expect(CalendarDate::from('2026-08-20')->isBefore(CalendarDate::from('2026-08-21')))
                ->toBeTrue();
        });

        it('compares across month and year boundaries', function () {
            expect(CalendarDate::from('2026-12-31')->isBefore(CalendarDate::from('2027-01-01')))
                ->toBeTrue()
                ->and(CalendarDate::from('2026-09-01')->isBefore(CalendarDate::from('2026-10-01')))
                ->toBeTrue();
        });

        it('returns false for the same date', function () {
            expect(CalendarDate::from('2026-08-20')->isBefore(CalendarDate::from('2026-08-20')))
                ->toBeFalse();
        });

        it('returns false when later', function () {
            expect(CalendarDate::from('2026-08-21')->isBefore(CalendarDate::from('2026-08-20')))
                ->toBeFalse();
        });
    });

    describe('isAfter()', function () {
        it('returns true when later', function () {
            expect(CalendarDate::from('2027-01-01')->isAfter(CalendarDate::from('2026-12-31')))
                ->toBeTrue();
        });

        it('returns false for the same date', function () {
            expect(CalendarDate::from('2026-08-20')->isAfter(CalendarDate::from('2026-08-20')))
                ->toBeFalse();
        });

        it('returns false when earlier', function () {
            expect(CalendarDate::from('2026-08-20')->isAfter(CalendarDate::from('2026-08-21')))
                ->toBeFalse();
        });
    });

    describe('__toString()', function () {
        it('renders the ISO date', function () {
            expect((string) CalendarDate::from('2026-08-20'))->toBe('2026-08-20');
        });
    });
});
