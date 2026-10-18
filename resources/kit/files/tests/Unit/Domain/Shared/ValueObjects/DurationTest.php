<?php

use App\Domain\Shared\Exceptions\DomainValueException;
use App\Domain\Shared\Exceptions\InvalidDurationException;
use App\Domain\Shared\ValueObjects\Duration;

describe('Duration', function () {
    describe('from', function () {
        it('holds whole seconds, from seconds, minutes or hours', function () {
            expect(Duration::fromSeconds(90)->seconds)->toBe(90)
                ->and(Duration::fromMinutes(90)->seconds)->toBe(5400)
                ->and(Duration::fromHours(2)->seconds)->toBe(7200)
                ->and(Duration::zero()->isZero())->toBeTrue();
        });

        it('refuses a negative length as an invalid value', function () {
            try {
                Duration::fromSeconds(-1);
                $this->fail('expected a negative duration to be refused');
            } catch (InvalidDurationException $e) {
                expect($e)->toBeInstanceOf(DomainValueException::class)
                    ->and($e->getCode())->toBe(InvalidDurationException::NEGATIVE)
                    ->and($e->context())->toBe(['seconds' => -1]);
            }
        });
    });

    describe('between', function () {
        it('is the time from one moment to a later one, whatever their zones', function () {
            $start = new DateTimeImmutable('2026-10-05 09:00:00', new DateTimeZone('Asia/Bangkok'));
            $end = new DateTimeImmutable('2026-10-05 03:30:00', new DateTimeZone('UTC'));

            expect(Duration::between($start, $end)->seconds)->toBe(5400);
        });

        it('refuses an end before the start', function () {
            $start = new DateTimeImmutable('2026-10-05 09:00:00');

            expect(fn () => Duration::between($start, $start->modify('-1 second')))
                ->toThrow(InvalidDurationException::class);
        });
    });

    describe('inMinutes and inHours', function () {
        it('counts the whole minutes and hours, dropping the rest', function () {
            expect(Duration::fromSeconds(5459)->inMinutes())->toBe(90)
                ->and(Duration::fromSeconds(5459)->inHours())->toBe(1);
        });
    });

    describe('after', function () {
        it('is the moment this long after another', function () {
            $sent = new DateTimeImmutable('2026-10-05 23:00:00', new DateTimeZone('Asia/Bangkok'));

            expect(Duration::fromHours(72)->after($sent)->format(DateTimeInterface::ATOM))->toBe('2026-10-08T23:00:00+07:00');
        });
    });

    describe('add and subtract', function () {
        it('adds and subtracts in seconds', function () {
            expect(Duration::fromMinutes(1)->add(Duration::fromSeconds(30))->seconds)->toBe(90)
                ->and(Duration::fromMinutes(1)->subtract(Duration::fromSeconds(30))->seconds)->toBe(30);
        });

        it('refuses a subtraction below zero', function () {
            expect(fn () => Duration::fromSeconds(1)->subtract(Duration::fromSeconds(2)))
                ->toThrow(InvalidDurationException::class);
        });
    });

    describe('comparisons', function () {
        it('compares by seconds', function () {
            $hour = Duration::fromHours(1);

            expect($hour->equals(Duration::fromMinutes(60)))->toBeTrue()
                ->and($hour->greaterThan(Duration::fromMinutes(59)))->toBeTrue()
                ->and($hour->greaterThanOrEqual(Duration::fromMinutes(60)))->toBeTrue()
                ->and($hour->lessThan(Duration::fromMinutes(61)))->toBeTrue()
                ->and($hour->lessThanOrEqual(Duration::fromMinutes(60)))->toBeTrue()
                ->and($hour->lessThan($hour))->toBeFalse();
        });
    });

    describe('format', function () {
        it('writes the length for a log line as hours, minutes and seconds', function () {
            expect(Duration::fromSeconds(5405)->format())->toBe('1:30:05')
                ->and(Duration::fromHours(26)->format())->toBe('26:00:00')
                ->and(Duration::zero()->format())->toBe('0:00:00');
        });
    });
});
