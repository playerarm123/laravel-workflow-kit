<?php

use App\Domain\Shared\Exceptions\DomainValueException;
use App\Domain\Shared\Exceptions\InvalidMoneyException;
use App\Domain\Shared\ValueObjects\Money;

describe('Money', function () {
    describe('from', function () {
        it('holds an amount in minor units, zero included', function () {
            expect(Money::from(150050)->amount)->toBe(150050)
                ->and(Money::from(0)->isZero())->toBeTrue()
                ->and(Money::zero()->amount)->toBe(0);
        });

        it('refuses a negative amount as an invalid value', function () {
            try {
                Money::from(-1);
                $this->fail('expected a negative amount to be refused');
            } catch (InvalidMoneyException $e) {
                expect($e)->toBeInstanceOf(DomainValueException::class)
                    ->and($e->getCode())->toBe(InvalidMoneyException::NEGATIVE_AMOUNT)
                    ->and($e->context())->toBe(['amount' => -1]);
            }
        });
    });

    describe('fromMajorUnit', function () {
        it('reads every decimal the decimal:0,2 rule lets through, exactly', function (string $input, int $minor) {
            expect(Money::fromMajorUnit($input)->amount)->toBe($minor);
        })->with([
            'two decimals' => ['1500.50', 150050],
            'one decimal' => ['1500.5', 150050],
            'no decimals' => ['1500', 150000],
            'a bare point' => ['5.', 500],
            'no whole part' => ['.05', 5],
            'a plus sign' => ['+7', 700],
            'zero' => ['0.00', 0],
            'leading zeros' => ['007.10', 710],
            'zeros past the scale' => ['1.500', 150],
            'a float would drift' => ['0.29', 29],
            'large' => ['92233720368547.75', 9223372036854775],
        ]);

        it('refuses a string that is not a decimal of at most two decimals', function (string $input) {
            try {
                Money::fromMajorUnit($input);
                $this->fail("expected '{$input}' to be refused");
            } catch (InvalidMoneyException $e) {
                expect($e->getCode())->toBe(InvalidMoneyException::MALFORMED)
                    ->and($e->context())->toBe(['amount' => $input]);
            }
        })->with([
            'empty' => [''],
            'a point alone' => ['.'],
            'a sign alone' => ['-'],
            'letters' => ['abc'],
            'an exponent' => ['1e3'],
            'a thousands separator' => ['1,500.50'],
            'spaces' => [' 15'],
            'three decimals' => ['1500.505'],
            'too many digits' => ['1234567890123456789'],
        ]);

        it('refuses a negative amount as negative, not as malformed', function () {
            try {
                Money::fromMajorUnit('-0.01');
                $this->fail('expected a negative amount to be refused');
            } catch (InvalidMoneyException $e) {
                expect($e->getCode())->toBe(InvalidMoneyException::NEGATIVE_AMOUNT)
                    ->and($e->context())->toBe(['amount' => -1]);
            }
        });
    });

    describe('add and subtract', function () {
        it('adds and subtracts in minor units', function () {
            expect(Money::from(150)->add(Money::from(50))->amount)->toBe(200)
                ->and(Money::from(150)->subtract(Money::from(50))->amount)->toBe(100);
        });

        it('refuses a subtraction below zero', function () {
            expect(fn () => Money::from(1)->subtract(Money::from(2)))
                ->toThrow(InvalidMoneyException::class);
        });
    });

    describe('comparisons', function () {
        it('compares by amount', function () {
            $ten = Money::from(1000);

            expect($ten->equals(Money::from(1000)))->toBeTrue()
                ->and($ten->greaterThan(Money::from(999)))->toBeTrue()
                ->and($ten->greaterThanOrEqual(Money::from(1000)))->toBeTrue()
                ->and($ten->lessThan(Money::from(1001)))->toBeTrue()
                ->and($ten->lessThanOrEqual(Money::from(1000)))->toBeTrue()
                ->and($ten->lessThan(Money::from(1000)))->toBeFalse()
                ->and($ten->isPositive())->toBeTrue()
                ->and(Money::zero()->isPositive())->toBeFalse();
        });
    });

    describe('toMajorUnit', function () {
        it('sends the amount as a decimal string with its two decimals fixed', function (int $minor, string $major) {
            expect(Money::from($minor)->toMajorUnit())->toBe($major);
        })->with([
            [50000, '500.00'],
            [150050, '1500.50'],
            [5, '0.05'],
            [0, '0.00'],
            [9223372036854775807, '92233720368547758.07'],
        ]);

        it('reads back what it sends', function () {
            expect(Money::fromMajorUnit(Money::from(123456)->toMajorUnit())->amount)->toBe(123456);
        });
    });

    describe('format', function () {
        it('writes the amount for a log line with thousands separators', function () {
            expect(Money::from(123456789)->format())->toBe('1,234,567.89')
                ->and(Money::from(5)->format())->toBe('0.05');
        });
    });
});
