<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * The scratch context and shared file of this file. Both carry `Sampling`, so no Architecture
 * check reads them, and differ from every other generator test's, so --parallel never deletes
 * them mid-run. A class loads once per process, so each case that loads a class names its own.
 */
const SAMPLING_VALUE_CONTEXT = 'SamplingValueObject';

const SAMPLING_VALUE_SHARED = 'SamplingSharedValueObject';

function samplingValuePath(string $class, string $aggregate = 'Crate'): string
{
    return app_path('Domain/'.SAMPLING_VALUE_CONTEXT."/{$aggregate}/ValueObjects/{$class}.php");
}

function samplingValueTestPath(string $class, string $aggregate = 'Crate'): string
{
    return base_path('tests/Unit/Domain/'.SAMPLING_VALUE_CONTEXT."/{$aggregate}/ValueObjects/{$class}Test.php");
}

function forgetSamplingValue(): void
{
    File::deleteDirectory(app_path('Domain/'.SAMPLING_VALUE_CONTEXT));
    File::deleteDirectory(base_path('tests/Unit/Domain/'.SAMPLING_VALUE_CONTEXT));
    File::delete([
        app_path('Domain/Shared/ValueObjects/'.SAMPLING_VALUE_SHARED.'.php'),
        base_path('tests/Unit/Domain/Shared/ValueObjects/'.SAMPLING_VALUE_SHARED.'Test.php'),
    ]);
}

beforeEach(fn () => forgetSamplingValue());

afterEach(fn () => forgetSamplingValue());

describe('make:value-object', function () {
    it('writes a value object whose fields name a builtin, its own enum and value object, the shared kernel and a global class', function () {
        $domain = SAMPLING_VALUE_CONTEXT.'/Crate';
        $this->artisan('make:enum', ['name' => 'CrateGrade', '--domain' => $domain, '--string' => true, '--case' => ['Top', 'Low']])->assertSuccessful();
        $this->artisan('make:value-object', ['name' => 'CrateNote', '--domain' => $domain, '--field' => ['text:string']])->assertSuccessful();
        $this->artisan('make:value-object', ['name' => 'CrateLabel', '--domain' => $domain, '--field' => [
            'grade:CrateGrade',
            'note:?CrateNote',
            'price:Shared/Money',
            'packedAt:DateTimeImmutable',
            'size:string|int',
        ]])->assertSuccessful();

        $namespace = 'App\Domain\\'.SAMPLING_VALUE_CONTEXT.'\Crate';

        expect(File::get(samplingValuePath('CrateLabel')))
            ->toContain("use {$namespace}\\Enums\\CrateGrade;")
            ->toContain('use App\Domain\Shared\ValueObjects\Money;')
            ->toContain('use DateTimeImmutable;')
            ->not->toContain("use {$namespace}\\ValueObjects\\CrateNote;")
            ->toContain('private readonly ?CrateNote $note,')
            ->toContain('private readonly string|int $size,')
            ->toContain('public static function from(CrateGrade $grade, ?CrateNote $note, Money $price, DateTimeImmutable $packedAt, string|int $size): self')
            ->toContain('$this->grade === $other->grade')
            ->toContain('($this->note === null ? $other->note === null : $other->note !== null && $this->note->equals($other->note))')
            ->toContain('$this->price->equals($other->price)')
            ->toContain('$this->packedAt == $other->packedAt')
            ->not->toContain('{{');

        $pint = Process::path(base_path())->run([base_path('vendor/bin/pint'), '--test', samplingValuePath('CrateLabel'), samplingValuePath('CrateNote')]);

        expect($pint->successful())->toBeTrue($pint->output());
    });

    it('writes a value object that loads, holds its values and compares them', function () {
        $this->artisan('make:value-object', ['name' => 'CrateSpot', '--domain' => SAMPLING_VALUE_CONTEXT.'/Crate', '--field' => ['row:int', 'shelf:string']])->assertSuccessful();

        $class = 'App\Domain\\'.SAMPLING_VALUE_CONTEXT.'\Crate\ValueObjects\CrateSpot';
        $spot = $class::from(3, 'B');

        expect($spot->row())->toBe(3)
            ->and($spot->shelf())->toBe('B')
            ->and($spot->equals($class::from(3, 'B')))->toBeTrue()
            ->and($spot->equals($class::from(4, 'B')))->toBeFalse();
    });

    it('writes a value object with no fields that pint accepts', function () {
        $this->artisan('make:value-object', ['name' => 'CrateNothing', '--domain' => SAMPLING_VALUE_CONTEXT.'/Crate'])->assertSuccessful();

        $pint = Process::path(base_path())->run([base_path('vendor/bin/pint'), '--test', samplingValuePath('CrateNothing'), samplingValueTestPath('CrateNothing')]);

        expect(File::get(samplingValuePath('CrateNothing')))->toContain('return new self;')
            ->and($pint->successful())->toBeTrue($pint->output());
    });

    it('writes a unit test mirroring where the value object lands, and never overwrites one', function () {
        $this->artisan('make:value-object', ['name' => 'CrateSeal', '--domain' => SAMPLING_VALUE_CONTEXT.'/Crate', '--field' => ['code:string']])->assertSuccessful();

        expect(File::get(samplingValueTestPath('CrateSeal')))
            ->toContain('use App\Domain\\'.SAMPLING_VALUE_CONTEXT.'\Crate\ValueObjects\CrateSeal;')
            ->toContain('@see CrateSeal')
            ->toContain("describe('CrateSeal'")
            ->toContain("describe('equals()'")
            ->toContain('->todo();');

        File::put(samplingValueTestPath('CrateSeal'), '<?php // kept');

        $this->artisan('make:value-object', ['name' => 'CrateSeal', '--domain' => SAMPLING_VALUE_CONTEXT.'/Crate', '--field' => ['code:string'], '--force' => true])
            ->expectsOutputToContain('already exists')
            ->assertSuccessful();

        expect(File::get(samplingValueTestPath('CrateSeal')))->toBe('<?php // kept');
    });

    it('writes into the shared kernel, naming its classes without a prefix', function () {
        $this->artisan('make:value-object', ['name' => SAMPLING_VALUE_SHARED, '--domain' => 'Shared', '--field' => ['price:Money']])->assertSuccessful();

        expect(File::get(app_path('Domain/Shared/ValueObjects/'.SAMPLING_VALUE_SHARED.'.php')))
            ->toContain('namespace App\Domain\Shared\ValueObjects;')
            ->not->toContain('use App\Domain\Shared\ValueObjects\Money;')
            ->toContain('private readonly Money $price,')
            ->and(File::exists(base_path('tests/Unit/Domain/Shared/ValueObjects/'.SAMPLING_VALUE_SHARED.'Test.php')))->toBeTrue();
    });

    it('refuses a home or a field that does not fit, and writes nothing', function (array $options, string $message) {
        $this->artisan('make:value-object', ['name' => 'CrateRefused', ...$options])
            ->expectsOutputToContain($message)
            ->assertFailed();

        expect(File::exists(samplingValuePath('CrateRefused')))->toBeFalse()
            ->and(File::exists(samplingValueTestPath('CrateRefused')))->toBeFalse();
    })->with([
        'a bare context' => [['--domain' => SAMPLING_VALUE_CONTEXT], 'A value object belongs to an aggregate or the shared kernel'],
        'a type not built yet' => [['--domain' => SAMPLING_VALUE_CONTEXT.'/Crate', '--field' => ['grade:CrateColour']], 'The type CrateColour of the field grade names no class yet'],
        'a field with no type' => [['--domain' => SAMPLING_VALUE_CONTEXT.'/Crate', '--field' => ['grade']], 'The field grade is not name:Type'],
        'a field not in camelCase' => [['--domain' => SAMPLING_VALUE_CONTEXT.'/Crate', '--field' => ['Grade:string']], 'The field Grade:string is not name:Type'],
        'a field given twice' => [['--domain' => SAMPLING_VALUE_CONTEXT.'/Crate', '--field' => ['code:string', 'code:int']], 'The field code is given twice'],
    ]);
});
