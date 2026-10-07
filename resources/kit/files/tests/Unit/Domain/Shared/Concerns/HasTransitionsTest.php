<?php

use App\Domain\Shared\Concerns\HasTransitions;

/**
 * A door that opens and shuts until it is bricked up, which ends it.
 */
enum HasTransitionsDoor: string
{
    use HasTransitions;

    case Open = 'open';
    case Shut = 'shut';
    case Bricked = 'bricked';

    /**
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Open => [self::Shut],
            self::Shut => [self::Open, self::Bricked],
            self::Bricked => [],
        };
    }
}

describe('HasTransitions', function () {
    describe('canBecome', function () {
        it('allows each status its enum lists next', function () {
            expect(HasTransitionsDoor::Open->canBecome(HasTransitionsDoor::Shut))->toBeTrue()
                ->and(HasTransitionsDoor::Shut->canBecome(HasTransitionsDoor::Open))->toBeTrue()
                ->and(HasTransitionsDoor::Shut->canBecome(HasTransitionsDoor::Bricked))->toBeTrue();
        });

        it('refuses a status the enum does not list, the same one included', function () {
            expect(HasTransitionsDoor::Open->canBecome(HasTransitionsDoor::Bricked))->toBeFalse()
                ->and(HasTransitionsDoor::Open->canBecome(HasTransitionsDoor::Open))->toBeFalse()
                ->and(HasTransitionsDoor::Bricked->canBecome(HasTransitionsDoor::Open))->toBeFalse();
        });
    });

    describe('isFinal', function () {
        it('is true only for a status that becomes nothing else', function () {
            expect(HasTransitionsDoor::Bricked->isFinal())->toBeTrue()
                ->and(HasTransitionsDoor::Open->isFinal())->toBeFalse()
                ->and(HasTransitionsDoor::Shut->isFinal())->toBeFalse();
        });
    });
});
