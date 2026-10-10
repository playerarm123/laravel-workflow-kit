<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;

/**
 * The scratch context of this file. It carries `Sampling`, so no Architecture check reads it, and
 * differs from every other generator test's, so --parallel never deletes it mid-run. A class loads
 * once per process, so each case that loads a value object names its own aggregate.
 */
const SAMPLING_VO_METHOD_CONTEXT = 'SamplingValueObjectMethod';

function samplingVoMethodPath(string $aggregate, string $valueObject): string
{
    return app_path('Domain/'.SAMPLING_VO_METHOD_CONTEXT."/{$aggregate}/ValueObjects/{$valueObject}.php");
}

function samplingVoMethodTestPath(string $aggregate, string $valueObject): string
{
    return base_path('tests/Unit/Domain/'.SAMPLING_VO_METHOD_CONTEXT."/{$aggregate}/ValueObjects/{$valueObject}Test.php");
}

function forgetSamplingVoMethod(): void
{
    File::deleteDirectory(app_path('Domain/'.SAMPLING_VO_METHOD_CONTEXT));
    File::deleteDirectory(base_path('tests/Unit/Domain/'.SAMPLING_VO_METHOD_CONTEXT));
}

/**
 * A value object with two fields, an enum and an invalid value of its aggregate, as the generators
 * write them.
 */
function samplingVoMethodAggregate(string $aggregate): void
{
    $domain = SAMPLING_VO_METHOD_CONTEXT."/{$aggregate}";

    test()->artisan('make:enum', ['name' => "{$aggregate}Grade", '--domain' => $domain, '--string' => true, '--case' => ['Top', 'Low']])->assertSuccessful();
    test()->artisan('make:domain-exception', ['name' => "{$aggregate}TagInvalid", '--domain' => $domain, '--kind' => 'value'])->assertSuccessful();
    test()->artisan('make:value-object', ['name' => "{$aggregate}Tag", '--domain' => $domain, '--field' => ['code:string', "grade:{$aggregate}Grade"]])->assertSuccessful();
}

beforeEach(fn () => forgetSamplingVoMethod());

afterEach(fn () => forgetSamplingVoMethod());

describe('make:value-object-method', function () {
    it('adds a behaviour that returns a copy built from the fields, naming its parameters and what it throws', function () {
        samplingVoMethodAggregate('Crate');

        $this->artisan('make:value-object-method', [
            'valueObject' => 'CrateTag',
            'method' => 'regrade',
            '--domain' => SAMPLING_VO_METHOD_CONTEXT.'/Crate',
            '--param' => ['grade:CrateGrade', 'price:Shared/Money', 'notes:...string'],
            '--throws' => ['Shared/InvalidMoneyException', 'CrateTagInvalidException'],
        ])->assertSuccessful();

        $code = File::get(samplingVoMethodPath('Crate', 'CrateTag'));

        expect($code)
            ->toContain('use App\Domain\\'.SAMPLING_VO_METHOD_CONTEXT.'\Crate\Exceptions\CrateTagInvalidException;')
            ->toContain('use App\Domain\Shared\Exceptions\InvalidMoneyException;')
            ->toContain('use App\Domain\Shared\ValueObjects\Money;')
            ->and(rtrim($code))->toEndWith("     * @throws CrateTagInvalidException\n     * @throws InvalidMoneyException\n     */\n    public function regrade(CrateGrade \$grade, Money \$price, string ...\$notes): self\n    {\n        return new self(\$this->code, \$this->grade);\n    }\n}");

        $pint = Process::path(base_path())->run([base_path('vendor/bin/pint'), '--test', samplingVoMethodPath('Crate', 'CrateTag')]);

        expect($pint->successful())->toBeTrue($pint->output());
    });

    it('adds an assertion that returns void, and the reader reads both back', function () {
        samplingVoMethodAggregate('Bin');
        $domain = SAMPLING_VO_METHOD_CONTEXT.'/Bin';

        $this->artisan('make:value-object-method', ['valueObject' => 'BinTag', 'method' => 'assertShort', '--domain' => $domain, '--throws' => ['BinTagInvalidException']])->assertSuccessful();
        $this->artisan('make:value-object-method', ['valueObject' => 'BinTag', 'method' => 'shorten', '--domain' => $domain])->assertSuccessful();

        expect(rtrim(File::get(samplingVoMethodPath('Bin', 'BinTag'))))->toContain("    public function assertShort(): void\n    {\n        //\n    }\n")
            ->and((new StructureReader(base_path()))->read(SAMPLING_VO_METHOD_CONTEXT)['valueObjects']['BinTag'])->toBe([
                'aggregate' => 'Bin',
                'fields' => ['code' => 'string', 'grade' => 'BinGrade'],
                'behaviours' => ['shorten' => ['params' => [], 'throws' => []]],
                'assertions' => ['assertShort' => ['params' => [], 'throws' => []]],
            ]);
    });

    it('gives the value object\'s test a describe for the method, with a todo for the case that passes and one per exception', function () {
        samplingVoMethodAggregate('Sack');

        $this->artisan('make:value-object-method', ['valueObject' => 'SackTag', 'method' => 'stitch', '--domain' => SAMPLING_VO_METHOD_CONTEXT.'/Sack', '--throws' => ['SackTagInvalidException']])->assertSuccessful();

        expect(File::get(samplingVoMethodTestPath('Sack', 'SackTag')))->toEndWith(<<<'PHP'
    describe('stitch()', function () {
        it('succeeds when its rules hold')->todo();

        it('throws SackTagInvalidException')->todo();
    });
});

PHP);
    });

    it('returns the value object itself when its constructor does not promote every field', function () {
        $path = samplingVoMethodPath('Box', 'BoxTag');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "<?php\n\nnamespace App\\Domain\\".SAMPLING_VO_METHOD_CONTEXT."\\Box\\ValueObjects;\n\nfinal class BoxTag\n{\n    private string \$code;\n\n    public function __construct(string \$code)\n    {\n        \$this->code = \$code;\n    }\n}\n");

        $this->artisan('make:value-object-method', ['valueObject' => 'BoxTag', 'method' => 'widen', '--domain' => SAMPLING_VO_METHOD_CONTEXT.'/Box'])
            ->expectsOutputToContain('does not exist')
            ->assertSuccessful();

        expect(rtrim(File::get($path)))->toEndWith("    public function widen(): self\n    {\n        return \$this;\n    }\n}");
    });

    it('refuses what it cannot write', function (string $valueObject, string $method, string $domain, array $options, string $message) {
        samplingVoMethodAggregate('Tub');

        $this->artisan('make:value-object-method', ['valueObject' => $valueObject, 'method' => $method, '--domain' => $domain, ...$options])
            ->expectsOutputToContain($message)
            ->assertFailed();
    })->with([
        'a name not camelCase' => ['TubTag', 'Widen', SAMPLING_VO_METHOD_CONTEXT.'/Tub', [], 'is not a camelCase name'],
        'a value object not built' => ['TubNote', 'widen', SAMPLING_VO_METHOD_CONTEXT.'/Tub', [], 'TubNote is not built yet'],
        'a method it already has' => ['TubTag', 'equals', SAMPLING_VO_METHOD_CONTEXT.'/Tub', [], 'TubTag already has equals()'],
        'a bare context' => ['TubTag', 'widen', SAMPLING_VO_METHOD_CONTEXT, [], 'belongs to an aggregate or the shared kernel'],
        'a parameter not name:Type' => ['TubTag', 'widen', SAMPLING_VO_METHOD_CONTEXT.'/Tub', ['--param' => ['Size']], 'is not name:Type'],
        'an exception not built' => ['TubTag', 'widen', SAMPLING_VO_METHOD_CONTEXT.'/Tub', ['--throws' => ['TubLostException']], 'TubLostException is not built yet'],
    ]);
});
