<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;

/**
 * The scratch context of this file. It carries `Sampling`, so no Architecture check reads it, and
 * differs from every other generator test's, so --parallel never deletes it mid-run. A class loads
 * once per process, so each case that loads an entity names its own aggregate.
 */
const SAMPLING_METHOD_CONTEXT = 'SamplingEntityMethod';

function samplingMethodPath(string $aggregate, ?string $child = null): string
{
    return app_path('Domain/'.SAMPLING_METHOD_CONTEXT."/{$aggregate}/".($child === null ? "{$aggregate}Entity.php" : "Entities/{$child}Entity.php"));
}

function samplingMethodTestPath(string $aggregate, ?string $child = null): string
{
    return base_path('tests/Unit/Domain/'.SAMPLING_METHOD_CONTEXT."/{$aggregate}/".($child === null ? "{$aggregate}EntityTest.php" : "Entities/{$child}EntityTest.php"));
}

function forgetSamplingMethod(): void
{
    File::deleteDirectory(app_path('Domain/'.SAMPLING_METHOD_CONTEXT));
    File::deleteDirectory(base_path('tests/Unit/Domain/'.SAMPLING_METHOD_CONTEXT));
}

/**
 * The root of an aggregate, its child and one refusal, as the generators write them.
 */
function samplingMethodAggregate(string $aggregate): void
{
    $domain = SAMPLING_METHOD_CONTEXT."/{$aggregate}";

    test()->artisan('make:entity', ['name' => $aggregate, '--domain' => $domain])->assertSuccessful();
    test()->artisan('make:entity', ['name' => "{$aggregate}Lid", '--domain' => $domain, '--child' => true])->assertSuccessful();
    test()->artisan('make:domain-exception', ['name' => "{$aggregate}Sealed", '--domain' => $domain, '--kind' => 'refusal'])->assertSuccessful();
    test()->artisan('make:enum', ['name' => "{$aggregate}Grade", '--domain' => $domain, '--string' => true, '--case' => ['Top', 'Low']])->assertSuccessful();
}

beforeEach(fn () => forgetSamplingMethod());

afterEach(fn () => forgetSamplingMethod());

describe('make:entity-method', function () {
    it('adds a behaviour at the end of the Behavior Section, naming its parameters and what it throws', function () {
        samplingMethodAggregate('Crate');
        $domain = SAMPLING_METHOD_CONTEXT.'/Crate';

        $this->artisan('make:entity-method', [
            'entity' => 'Crate',
            'method' => 'seal',
            '--domain' => $domain,
            '--param' => ['grade:CrateGrade', 'price:Shared/Money', 'tags:...string'],
            '--throws' => ['Shared/InvalidMoneyException', 'CrateSealedException'],
        ])->assertSuccessful();

        $namespace = 'App\Domain\\'.SAMPLING_METHOD_CONTEXT.'\Crate';
        $code = File::get(samplingMethodPath('Crate'));

        expect($code)
            ->toContain("use {$namespace}\\Enums\\CrateGrade;")
            ->toContain("use {$namespace}\\Exceptions\\CrateSealedException;")
            ->toContain('use App\Domain\Shared\Exceptions\InvalidMoneyException;')
            ->toContain('use App\Domain\Shared\ValueObjects\Money;')
            ->toContain("     * @throws CrateSealedException\n     * @throws InvalidMoneyException\n     */\n    public function seal(CrateGrade \$grade, Money \$price, string ...\$tags): void\n")
            ->and(strpos($code, 'function seal('))->toBeGreaterThan((int) strpos($code, 'Behavior Section'))
            ->and(strpos($code, 'function seal('))->toBeLessThan((int) strpos($code, 'Getter Section'));

        $pint = Process::path(base_path())->run([base_path('vendor/bin/pint'), '--test', samplingMethodPath('Crate')]);

        expect($pint->successful())->toBeTrue($pint->output());
    });

    it('adds an assertion at the end of the Asserting Section, and the method loads as one the reader reads back', function () {
        samplingMethodAggregate('Bin');
        $domain = SAMPLING_METHOD_CONTEXT.'/Bin';

        $this->artisan('make:entity-method', ['entity' => 'Bin', 'method' => 'assertOpen', '--domain' => $domain, '--throws' => ['BinSealedException']])->assertSuccessful();
        $this->artisan('make:entity-method', ['entity' => 'Bin', 'method' => 'empty', '--domain' => $domain])->assertSuccessful();

        $code = File::get(samplingMethodPath('Bin'));

        expect(strpos($code, 'function assertOpen('))->toBeGreaterThan((int) strpos($code, 'Asserting Section'))
            ->and(rtrim($code))->toEndWith("public function assertOpen(): void\n    {\n        //\n    }\n}")
            ->and((new StructureReader(base_path()))->read(SAMPLING_METHOD_CONTEXT)['entities']['Bin'])->toBe([
                'aggregate' => 'Bin',
                'behaviours' => ['empty' => ['params' => [], 'throws' => []]],
                'assertions' => ['assertOpen' => ['params' => [], 'throws' => []]],
            ]);

        $pint = Process::path(base_path())->run([base_path('vendor/bin/pint'), '--test', samplingMethodPath('Bin')]);

        expect($pint->successful())->toBeTrue($pint->output());
    });

    it('adds a behaviour to a child entity, importing the exception from its aggregate', function () {
        samplingMethodAggregate('Tray');

        $this->artisan('make:entity-method', ['entity' => 'TrayLid', 'method' => 'open', '--domain' => SAMPLING_METHOD_CONTEXT.'/Tray', '--throws' => ['TraySealedException']])->assertSuccessful();

        expect(File::get(samplingMethodPath('Tray', 'TrayLid')))
            ->toContain('use App\Domain\\'.SAMPLING_METHOD_CONTEXT.'\Tray\Exceptions\TraySealedException;')
            ->toContain('public function open(): void');
    });

    it('gives the entity\'s test a describe for the method, with a todo for the case that passes and one per exception', function () {
        samplingMethodAggregate('Sack');

        $this->artisan('make:entity-method', ['entity' => 'Sack', 'method' => 'stitch', '--domain' => SAMPLING_METHOD_CONTEXT.'/Sack', '--throws' => ['SackSealedException', 'Shared/InvalidMoneyException']])->assertSuccessful();

        expect(File::get(samplingMethodTestPath('Sack')))->toEndWith(<<<'PHP'
    describe('reconstitute()', function () {
        it('rebuilds the entity exactly as it was stored')->todo();
    });

    describe('stitch()', function () {
        it('succeeds when its rules hold')->todo();

        it('throws InvalidMoneyException')->todo();

        it('throws SackSealedException')->todo();
    });
});

PHP);
    });

    it('writes into an entity with no sections before it closes, and warns', function () {
        $path = samplingMethodPath('Box');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "<?php\n\nnamespace App\\Domain\\".SAMPLING_METHOD_CONTEXT."\\Box;\n\nfinal class BoxEntity\n{\n    public function id(): string\n    {\n        return 'box';\n    }\n}\n");

        $this->artisan('make:entity-method', ['entity' => 'Box', 'method' => 'close', '--domain' => SAMPLING_METHOD_CONTEXT.'/Box'])
            ->expectsOutputToContain('BoxEntity has no Behavior Section')
            ->expectsOutputToContain('does not exist')
            ->assertSuccessful();

        expect(File::get($path))->toEndWith("        return 'box';\n    }\n\n    public function close(): void\n    {\n        //\n    }\n}\n");
    });

    it('refuses what it cannot write, and leaves the entity as it was', function (array $options, string $message) {
        samplingMethodAggregate('Pallet');
        $this->artisan('make:entity-method', ['entity' => 'Pallet', 'method' => 'stack', '--domain' => SAMPLING_METHOD_CONTEXT.'/Pallet'])->assertSuccessful();
        $before = File::get(samplingMethodPath('Pallet'));

        $this->artisan('make:entity-method', ['entity' => 'Pallet', '--domain' => SAMPLING_METHOD_CONTEXT.'/Pallet', ...$options])
            ->expectsOutputToContain($message)
            ->assertFailed();

        expect(File::get(samplingMethodPath('Pallet')))->toBe($before);
    })->with([
        'a method it already has' => [['method' => 'stack'], 'PalletEntity already has stack().'],
        'a name that is not camelCase' => [['method' => 'Stack'], 'The method Stack is not a camelCase name.'],
        'a parameter with no type' => [['method' => 'wrap', '--param' => ['film']], 'The parameter film is not name:Type'],
        'a type not built yet' => [['method' => 'wrap', '--param' => ['film:PalletFilm']], 'The type PalletFilm of the parameter film names no class yet'],
        'an exception not built yet' => [['method' => 'wrap', '--throws' => ['PalletTornException']], 'The exception PalletTornException is not built yet'],
        'an entity not built yet' => [['entity' => 'PalletCorner', 'method' => 'wrap'], 'PalletCornerEntity is not built yet'],
    ]);

    it('refuses a domain that is not an aggregate', function () {
        $this->artisan('make:entity-method', ['entity' => 'Crate', 'method' => 'seal', '--domain' => SAMPLING_METHOD_CONTEXT])
            ->expectsOutputToContain('An entity belongs to an aggregate')
            ->assertFailed();
    });
});
