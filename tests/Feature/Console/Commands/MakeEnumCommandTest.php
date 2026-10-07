<?php

use Illuminate\Support\Facades\File;

/**
 * The scratch context and shared file of this file. Both carry `Sampling`, so no Architecture
 * check reads them, and differ from every other generator test's, so --parallel never deletes
 * them mid-run. A class loads once per process, so each case that loads an enum names its own.
 */
const SAMPLING_ENUM_CONTEXT = 'SamplingEnum';

const SAMPLING_ENUM_SHARED = 'SamplingSharedEnum';

function samplingEnumPath(string $enum, string $aggregate = 'Crate'): string
{
    return app_path('Domain/'.SAMPLING_ENUM_CONTEXT."/{$aggregate}/Enums/{$enum}.php");
}

function forgetSamplingEnum(): void
{
    File::deleteDirectory(app_path('Domain/'.SAMPLING_ENUM_CONTEXT));
    File::delete(app_path('Domain/Shared/Enums/'.SAMPLING_ENUM_SHARED.'.php'));
}

beforeEach(fn () => forgetSamplingEnum());

afterEach(fn () => forgetSamplingEnum());

describe('make:enum', function () {
    it('writes a string backed enum into the aggregate, its cases in order, a missing value in snake case', function () {
        $this->artisan('make:enum', ['name' => 'CrateGrade', '--domain' => SAMPLING_ENUM_CONTEXT.'/Crate', '--string' => true, '--case' => ['Top', 'BottomShelf', 'Held=on_hold']])
            ->assertSuccessful();

        $enum = 'App\Domain\\'.SAMPLING_ENUM_CONTEXT.'\Crate\Enums\CrateGrade';

        expect(File::get(samplingEnumPath('CrateGrade')))
            ->toContain('namespace App\Domain\\'.SAMPLING_ENUM_CONTEXT.'\Crate\Enums;')
            ->toContain('enum CrateGrade: string')
            ->not->toContain('//')
            ->and(array_map(fn (BackedEnum $case): array => [$case->name, $case->value], $enum::cases()))
            ->toBe([['Top', 'top'], ['BottomShelf', 'bottom_shelf'], ['Held', 'on_hold']]);
    });

    it('writes an int backed enum and a pure one', function () {
        $this->artisan('make:enum', ['name' => 'CrateSize', '--domain' => SAMPLING_ENUM_CONTEXT.'/Crate', '--int' => true, '--case' => ['Half=50', 'Full=100']])->assertSuccessful();
        $this->artisan('make:enum', ['name' => 'CrateSide', '--domain' => SAMPLING_ENUM_CONTEXT.'/Crate', '--case' => ['Left', 'Right']])->assertSuccessful();

        $size = 'App\Domain\\'.SAMPLING_ENUM_CONTEXT.'\Crate\Enums\CrateSize';
        $side = 'App\Domain\\'.SAMPLING_ENUM_CONTEXT.'\Crate\Enums\CrateSide';

        expect(array_map(fn (BackedEnum $case): int|string => $case->value, $size::cases()))->toBe([50, 100])
            ->and(array_map(fn (UnitEnum $case): string => $case->name, $side::cases()))->toBe(['Left', 'Right'])
            ->and(File::get(samplingEnumPath('CrateSide')))->toContain("enum CrateSide\n");
    });

    it('writes a status whose transitions name every case, each going nowhere until a person fills it in', function () {
        $this->artisan('make:enum', ['name' => 'CrateStatus', '--domain' => SAMPLING_ENUM_CONTEXT.'/Crate', '--string' => true, '--case' => ['Open', 'Sealed'], '--transitions' => true])
            ->assertSuccessful();

        $enum = 'App\Domain\\'.SAMPLING_ENUM_CONTEXT.'\Crate\Enums\CrateStatus';

        expect(File::get(samplingEnumPath('CrateStatus')))
            ->toContain("use App\Domain\Shared\Concerns\HasTransitions;\n")
            ->toContain("{\n    use HasTransitions;\n\n    case Open = 'open';")
            ->toContain("        return match (\$this) {\n            self::Open => [],\n            self::Sealed => [],\n        };")
            ->toContain('@todo List the cases each one may become next')
            ->and($enum::Open->isFinal())->toBeTrue()
            ->and($enum::Open->canBecome($enum::Sealed))->toBeFalse();
    });

    it('writes a status with where each case may go, a case no transition names being final', function () {
        $this->artisan('make:enum', ['name' => 'CrateRoute', '--domain' => SAMPLING_ENUM_CONTEXT.'/Crate', '--string' => true, '--case' => ['Open', 'Sealed', 'Shipped'], '--transition' => ['Open:Sealed, Shipped', 'Sealed:Shipped']])
            ->assertSuccessful();

        $enum = 'App\Domain\\'.SAMPLING_ENUM_CONTEXT.'\Crate\Enums\CrateRoute';

        expect(File::get(samplingEnumPath('CrateRoute')))
            ->toContain("{\n    use HasTransitions;\n\n    case Open = 'open';")
            ->toContain("        return match (\$this) {\n            self::Open => [self::Sealed, self::Shipped],\n            self::Sealed => [self::Shipped],\n            self::Shipped => [],\n        };")
            ->toContain("     * @return list<self>\n     */")
            ->not->toContain('@todo')
            ->and($enum::Open->canBecome($enum::Shipped))->toBeTrue()
            ->and($enum::Sealed->canBecome($enum::Open))->toBeFalse()
            ->and($enum::Shipped->isFinal())->toBeTrue();
    });

    it('writes an enum with no cases as the stub leaves it', function () {
        $this->artisan('make:enum', ['name' => 'CrateMood', '--domain' => SAMPLING_ENUM_CONTEXT.'/Crate', '--string' => true])->assertSuccessful();

        expect(File::get(samplingEnumPath('CrateMood')))->toContain("enum CrateMood: string\n{\n    //\n}");
    });

    it('writes into the shared kernel', function () {
        $this->artisan('make:enum', ['name' => SAMPLING_ENUM_SHARED, '--domain' => 'Shared', '--string' => true, '--case' => ['Loud']])->assertSuccessful();

        expect(File::get(app_path('Domain/Shared/Enums/'.SAMPLING_ENUM_SHARED.'.php')))
            ->toContain('namespace App\Domain\Shared\Enums;')
            ->toContain("case Loud = 'loud';");
    });

    it('refuses a home or a case that does not fit, and writes nothing', function (array $options, string $message) {
        $this->artisan('make:enum', ['name' => 'CrateRefused', ...$options])
            ->expectsOutputToContain($message)
            ->assertFailed();

        expect(File::exists(samplingEnumPath('CrateRefused')))->toBeFalse();
    })->with([
        'a bare context' => [['--domain' => SAMPLING_ENUM_CONTEXT, '--string' => true], 'An enum belongs to an aggregate or the shared kernel'],
        'a value on a pure case' => [['--domain' => SAMPLING_ENUM_CONTEXT.'/Crate', '--case' => ['Top=top']], 'The case Top has a value, but the enum is not backed'],
        'an int case with no value' => [['--domain' => SAMPLING_ENUM_CONTEXT.'/Crate', '--int' => true, '--case' => ['Top']], 'The case Top needs an integer value'],
        'an empty string value' => [['--domain' => SAMPLING_ENUM_CONTEXT.'/Crate', '--string' => true, '--case' => ['Top=']], 'The case Top has an empty value'],
        'a case not in TitleCase' => [['--domain' => SAMPLING_ENUM_CONTEXT.'/Crate', '--string' => true, '--case' => ['top']], 'The case top is not TitleCase'],
        'a status with no cases' => [['--domain' => SAMPLING_ENUM_CONTEXT.'/Crate', '--string' => true, '--transitions' => true], 'A status lists where each case goes'],
        'a transition that is not Case:Next' => [['--domain' => SAMPLING_ENUM_CONTEXT.'/Crate', '--string' => true, '--case' => ['Open', 'Sealed'], '--transition' => ['Open>Sealed']], 'The transition Open>Sealed is not Case:Next,Next'],
        'a transition from no case' => [['--domain' => SAMPLING_ENUM_CONTEXT.'/Crate', '--string' => true, '--case' => ['Open', 'Sealed'], '--transition' => ['Lost:Open']], 'starts from Lost, which is not one of the cases'],
        'a transition to no case' => [['--domain' => SAMPLING_ENUM_CONTEXT.'/Crate', '--string' => true, '--case' => ['Open', 'Sealed'], '--transition' => ['Open:Lost']], 'goes to Lost, which is not one of the cases'],
        'a case that becomes itself' => [['--domain' => SAMPLING_ENUM_CONTEXT.'/Crate', '--string' => true, '--case' => ['Open', 'Sealed'], '--transition' => ['Open:Open,Sealed']], 'lets Open become itself'],
        'a case given two transitions' => [['--domain' => SAMPLING_ENUM_CONTEXT.'/Crate', '--string' => true, '--case' => ['Open', 'Sealed'], '--transition' => ['Open:Sealed', 'Open:Sealed']], 'The case Open is given two transitions'],
        'a target named twice' => [['--domain' => SAMPLING_ENUM_CONTEXT.'/Crate', '--string' => true, '--case' => ['Open', 'Sealed'], '--transition' => ['Open:Sealed,Sealed']], 'names a case twice'],
    ]);

    it('fails without a domain when it cannot ask for one', function () {
        $this->artisan('make:enum', ['name' => 'CrateRefused', '--string' => true, '--no-interaction' => true])
            ->expectsOutputToContain('Pass --domain')
            ->assertFailed();
    });
});
