<?php

use App\Domain\Shared\Exceptions\DomainValueException;
use App\Domain\Shared\Exceptions\InvalidTimeOfDayException;
use App\Domain\Shared\ValueObjects\TimeOfDay;

describe('TimeOfDay', function () {
    describe('from()', function () {
        it('creates from valid minutes', function () {
            $time = TimeOfDay::from(570);

            expect($time->minutes)->toBe(570);
        });

        it('accepts start of day', function () {
            $time = TimeOfDay::from(0);

            expect($time->minutes)->toBe(0);
        });

        it('accepts end of day', function () {
            $time = TimeOfDay::from(1439);

            expect($time->minutes)->toBe(1439);
        });

        it('rejects negative minutes', function () {
            expect(fn () => TimeOfDay::from(-1))
                ->toThrow(InvalidTimeOfDayException::class);
        });

        it('rejects minutes beyond end of day', function () {
            expect(fn () => TimeOfDay::from(1440))
                ->toThrow(InvalidTimeOfDayException::class);
        });
    });

    describe('fromHourMinute()', function () {
        it('creates from hour and minute', function () {
            $time = TimeOfDay::fromHourMinute(9, 30);

            expect($time->minutes)->toBe(570);
        });

        it('accepts 00:00', function () {
            $time = TimeOfDay::fromHourMinute(0, 0);

            expect($time->minutes)->toBe(0);
        });

        it('accepts 23:59', function () {
            $time = TimeOfDay::fromHourMinute(23, 59);

            expect($time->minutes)->toBe(1439);
        });

        it('rejects invalid hour', function () {
            expect(fn () => TimeOfDay::fromHourMinute(24, 0))
                ->toThrow(InvalidTimeOfDayException::class);
        });

        it('rejects negative hour', function () {
            expect(fn () => TimeOfDay::fromHourMinute(-1, 0))
                ->toThrow(InvalidTimeOfDayException::class);
        });

        it('rejects invalid minute', function () {
            expect(fn () => TimeOfDay::fromHourMinute(12, 60))
                ->toThrow(InvalidTimeOfDayException::class);
        });

        it('rejects negative minute', function () {
            expect(fn () => TimeOfDay::fromHourMinute(12, -1))
                ->toThrow(InvalidTimeOfDayException::class);
        });
    });

    describe('fromString()', function () {
        it('creates from valid HH:mm string', function () {
            $time = TimeOfDay::fromString('09:30');

            expect($time->minutes)->toBe(570);
        });

        it('accepts 00:00', function () {
            $time = TimeOfDay::fromString('00:00');

            expect($time->minutes)->toBe(0);
        });

        it('accepts 23:59', function () {
            $time = TimeOfDay::fromString('23:59');

            expect($time->minutes)->toBe(1439);
        });

        it('rejects invalid hour', function () {
            expect(fn () => TimeOfDay::fromString('24:00'))
                ->toThrow(InvalidTimeOfDayException::class);
        });

        it('rejects invalid minute', function () {
            expect(fn () => TimeOfDay::fromString('12:60'))
                ->toThrow(InvalidTimeOfDayException::class);
        });

        it('rejects missing leading zero', function () {
            expect(fn () => TimeOfDay::fromString('9:30'))
                ->toThrow(InvalidTimeOfDayException::class);
        });

        it('rejects invalid format', function (string $time) {
            expect(fn () => TimeOfDay::fromString($time))
                ->toThrow(InvalidTimeOfDayException::class);
        })->with([
            '930',
            '09-30',
            '09:30:00',
            'abc',
            '',
            ' 09:30',
            '09:30 ',
        ]);
    });

    describe('startOfDay()', function () {
        it('returns 00:00', function () {
            $time = TimeOfDay::startOfDay();

            expect($time->minutes)->toBe(0)
                ->and($time->format())->toBe('00:00');
        });
    });

    describe('endOfDay()', function () {
        it('returns 23:59', function () {
            $time = TimeOfDay::endOfDay();

            expect($time->minutes)->toBe(1439)
                ->and($time->format())->toBe('23:59');
        });
    });

    describe('hour()', function () {
        it('returns the hour', function () {
            expect(TimeOfDay::from(0)->hour())->toBe(0)
                ->and(TimeOfDay::from(570)->hour())->toBe(9)
                ->and(TimeOfDay::from(1439)->hour())->toBe(23);
        });
    });

    describe('minute()', function () {
        it('returns the minute', function () {
            expect(TimeOfDay::from(0)->minute())->toBe(0)
                ->and(TimeOfDay::from(570)->minute())->toBe(30)
                ->and(TimeOfDay::from(1439)->minute())->toBe(59);
        });
    });

    describe('addMinutes()', function () {
        it('adds minutes', function () {
            $time = TimeOfDay::fromString('09:30');

            expect($time->addMinutes(30)->format())->toBe('10:00');
        });

        it('wraps to the next day', function () {
            $time = TimeOfDay::fromString('23:30');

            expect($time->addMinutes(60)->format())->toBe('00:30');
        });

        it('wraps when adding exactly one day', function () {
            $time = TimeOfDay::fromString('09:30');

            expect($time->addMinutes(1440)->format())->toBe('09:30');
        });

        it('supports negative minutes', function () {
            $time = TimeOfDay::fromString('00:30');

            expect($time->addMinutes(-60)->format())->toBe('23:30');
        });

        it('supports more than one day', function () {
            $time = TimeOfDay::fromString('09:30');

            expect($time->addMinutes(2880 + 30)->format())->toBe('10:00');
        });
    });

    describe('subtractMinutes()', function () {
        it('subtracts minutes', function () {
            $time = TimeOfDay::fromString('10:00');

            expect($time->subtractMinutes(30)->format())->toBe('09:30');
        });

        it('wraps to the previous day', function () {
            $time = TimeOfDay::fromString('00:30');

            expect($time->subtractMinutes(60)->format())->toBe('23:30');
        });
    });

    describe('diffInMinutes()', function () {
        it('returns the difference in minutes', function () {
            $start = TimeOfDay::fromString('09:00');
            $end = TimeOfDay::fromString('10:30');

            expect($end->diffInMinutes($start))->toBe(90);
        });

        it('can return a negative difference', function () {
            $start = TimeOfDay::fromString('10:30');
            $end = TimeOfDay::fromString('09:00');

            expect($end->diffInMinutes($start))->toBe(-90);
        });

        it('returns zero for the same time', function () {
            $time = TimeOfDay::fromString('09:00');

            expect($time->diffInMinutes($time))->toBe(0);
        });
    });

    describe('equals()', function () {
        it('returns true for equal times', function () {
            expect(
                TimeOfDay::fromString('09:30')
                    ->equals(TimeOfDay::from(570))
            )->toBeTrue();
        });

        it('returns false for different times', function () {
            expect(
                TimeOfDay::fromString('09:30')
                    ->equals(TimeOfDay::fromString('10:30'))
            )->toBeFalse();
        });
    });

    describe('comparisons', function () {
        it('checks greater than', function () {
            $time = TimeOfDay::fromString('10:00');

            expect($time->greaterThan(TimeOfDay::fromString('09:00')))->toBeTrue()
                ->and($time->greaterThan(TimeOfDay::fromString('10:00')))->toBeFalse();
        });

        it('checks greater than or equal', function () {
            $time = TimeOfDay::fromString('10:00');

            expect($time->greaterThanOrEqual(TimeOfDay::fromString('09:00')))->toBeTrue()
                ->and($time->greaterThanOrEqual(TimeOfDay::fromString('10:00')))->toBeTrue()
                ->and($time->greaterThanOrEqual(TimeOfDay::fromString('11:00')))->toBeFalse();
        });

        it('checks less than', function () {
            $time = TimeOfDay::fromString('10:00');

            expect($time->lessThan(TimeOfDay::fromString('11:00')))->toBeTrue()
                ->and($time->lessThan(TimeOfDay::fromString('10:00')))->toBeFalse();
        });

        it('checks less than or equal', function () {
            $time = TimeOfDay::fromString('10:00');

            expect($time->lessThanOrEqual(TimeOfDay::fromString('11:00')))->toBeTrue()
                ->and($time->lessThanOrEqual(TimeOfDay::fromString('10:00')))->toBeTrue()
                ->and($time->lessThanOrEqual(TimeOfDay::fromString('09:00')))->toBeFalse();
        });
    });

    describe('isBetween() with overnight range', function () {
        it('returns true for time after start', function () {
            $time = TimeOfDay::fromString('23:30');

            expect(
                $time->isBetween(
                    TimeOfDay::fromString('23:00'),
                    TimeOfDay::fromString('02:00'),
                )
            )->toBeTrue();
        });

        it('returns true for time before end', function () {
            $time = TimeOfDay::fromString('01:30');

            expect(
                $time->isBetween(
                    TimeOfDay::fromString('23:00'),
                    TimeOfDay::fromString('02:00'),
                )
            )->toBeTrue();
        });

        it('includes the start boundary', function () {
            $time = TimeOfDay::fromString('23:00');

            expect(
                $time->isBetween(
                    TimeOfDay::fromString('23:00'),
                    TimeOfDay::fromString('02:00'),
                )
            )->toBeTrue();
        });

        it('includes the end boundary', function () {
            $time = TimeOfDay::fromString('02:00');

            expect(
                $time->isBetween(
                    TimeOfDay::fromString('23:00'),
                    TimeOfDay::fromString('02:00'),
                )
            )->toBeTrue();
        });

        it('returns false for time outside overnight range', function () {
            $time = TimeOfDay::fromString('12:00');

            expect(
                $time->isBetween(
                    TimeOfDay::fromString('23:00'),
                    TimeOfDay::fromString('02:00'),
                )
            )->toBeFalse();
        });
    });

    describe('format()', function () {
        it('formats time as HH:mm', function () {
            expect(TimeOfDay::from(0)->format())->toBe('00:00')
                ->and(TimeOfDay::from(570)->format())->toBe('09:30')
                ->and(TimeOfDay::from(1439)->format())->toBe('23:59');
        });
    });

    describe('the invalid value it refuses with', function () {
        it('names the reason and carries the offending input', function (Closure $build, int $code, array $context) {
            try {
                $build();
                $this->fail('expected the time to be refused');
            } catch (InvalidTimeOfDayException $e) {
                expect($e)->toBeInstanceOf(DomainValueException::class)
                    ->and($e->getCode())->toBe($code)
                    ->and($e->context())->toBe($context);
            }
        })->with([
            'minutes past the day' => [fn () => TimeOfDay::from(1440), InvalidTimeOfDayException::MINUTES_OUT_OF_RANGE, ['minutes' => 1440]],
            'an hour past 23' => [fn () => TimeOfDay::fromHourMinute(24, 0), InvalidTimeOfDayException::HOUR_OUT_OF_RANGE, ['hour' => 24]],
            'a minute past 59' => [fn () => TimeOfDay::fromHourMinute(12, 60), InvalidTimeOfDayException::MINUTE_OUT_OF_RANGE, ['minute' => 60]],
            'a string not HH:mm' => [fn () => TimeOfDay::fromString('9:30'), InvalidTimeOfDayException::MALFORMED, ['time' => '9:30']],
        ]);
    });
});
