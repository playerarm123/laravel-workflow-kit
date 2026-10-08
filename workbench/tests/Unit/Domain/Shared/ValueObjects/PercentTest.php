<?php

use App\Domain\Shared\Exceptions\DomainValueException;
use App\Domain\Shared\Exceptions\InvalidPercentException;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\Percent;

describe('Percent', function () {
    describe('fromBasisPoints', function () {
        it('holds a rate in basis points, zero included', function () {
            expect(Percent::fromBasisPoints(500)->basisPoints)->toBe(500)
                ->and(Percent::zero()->isZero())->toBeTrue();
        });

        it('refuses a negative rate as an invalid value', function () {
            try {
                Percent::fromBasisPoints(-1);
                $this->fail('expected a negative percent to be refused');
            } catch (InvalidPercentException $e) {
                expect($e)->toBeInstanceOf(DomainValueException::class)
                    ->and($e->getCode())->toBe(InvalidPercentException::NEGATIVE)
                    ->and($e->context())->toBe(['basisPoints' => -1]);
            }
        });
    });

    describe('fromPercent', function () {
        it('reads a rate in percent exactly', function (string $input, int $basisPoints) {
            expect(Percent::fromPercent($input)->basisPoints)->toBe($basisPoints);
        })->with([
            ['2.5', 250],
            ['5.25', 525],
            ['100', 10000],
            ['.01', 1],
            ['0.29', 29],
        ]);

        it('refuses a string that is not a decimal of at most two decimals', function (string $input) {
            try {
                Percent::fromPercent($input);
                $this->fail("expected '{$input}' to be refused");
            } catch (InvalidPercentException $e) {
                expect($e->getCode())->toBe(InvalidPercentException::MALFORMED)
                    ->and($e->context())->toBe(['percent' => $input]);
            }
        })->with([[''], ['abc'], ['5%'], ['2.555'], ['1e2']]);

        it('refuses a negative rate as negative, not as malformed', function () {
            expect(fn () => Percent::fromPercent('-1'))
                ->toThrow(fn (InvalidPercentException $e) => expect($e->getCode())->toBe(InvalidPercentException::NEGATIVE));
        });
    });

    describe('add and subtract', function () {
        it('adds and subtracts in basis points, never below zero', function () {
            expect(Percent::fromBasisPoints(500)->add(Percent::fromBasisPoints(250))->basisPoints)->toBe(750)
                ->and(Percent::fromBasisPoints(500)->subtract(Percent::fromBasisPoints(250))->basisPoints)->toBe(250)
                ->and(fn () => Percent::fromBasisPoints(1)->subtract(Percent::fromBasisPoints(2)))->toThrow(InvalidPercentException::class);
        });
    });

    describe('comparisons', function () {
        it('compares by basis points', function () {
            $five = Percent::fromBasisPoints(500);

            expect($five->equals(Percent::fromPercent('5')))->toBeTrue()
                ->and($five->greaterThan(Percent::fromBasisPoints(499)))->toBeTrue()
                ->and($five->greaterThanOrEqual(Percent::fromBasisPoints(500)))->toBeTrue()
                ->and($five->lessThan(Percent::fromBasisPoints(501)))->toBeTrue()
                ->and($five->lessThanOrEqual(Percent::fromBasisPoints(500)))->toBeTrue()
                ->and($five->isPositive())->toBeTrue()
                ->and(Percent::zero()->isPositive())->toBeFalse();
        });
    });

    describe('toPercent', function () {
        it('sends the rate in percent as a decimal string with two decimals', function () {
            expect(Percent::fromBasisPoints(500)->toPercent())->toBe('5.00')
                ->and(Percent::fromBasisPoints(1234)->toPercent())->toBe('12.34')
                ->and(Percent::fromBasisPoints(5)->toPercent())->toBe('0.05')
                ->and(Percent::zero()->toPercent())->toBe('0.00');
        });
    });

    describe('applyTo', function () {
        it('takes the rate of an amount, rounding half up to the minor unit', function (int $basisPoints, int $amount, int $taken) {
            expect(Percent::fromBasisPoints($basisPoints)->applyTo(Money::from($amount))->amount)->toBe($taken);
        })->with([
            'exact' => [500, 10000, 500],
            'rounds down below half' => [500, 1001, 50],
            'rounds up at half' => [500, 1010, 51],
            'all of it' => [10000, 12345, 12345],
            'none of it' => [0, 12345, 0],
        ]);
    });

    describe('format', function () {
        it('writes the rate for a log line', function () {
            expect(Percent::fromBasisPoints(525)->format())->toBe('5.25%');
        });
    });
});
