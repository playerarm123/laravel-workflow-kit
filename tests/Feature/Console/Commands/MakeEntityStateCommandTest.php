<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;

/**
 * The scratch context of this file. It carries `Sampling`, so no Architecture check reads it, and
 * differs from every other generator test's, so --parallel never deletes it mid-run. A class loads
 * once per process, so each case that loads an entity names its own aggregate.
 */
const SAMPLING_STATE_CONTEXT = 'SamplingEntityState';

function samplingStatePath(string $aggregate, ?string $child = null): string
{
    return app_path('Domain/'.SAMPLING_STATE_CONTEXT."/{$aggregate}/".($child === null ? "{$aggregate}Entity.php" : "Entities/{$child}Entity.php"));
}

function samplingStateTestPath(string $aggregate): string
{
    return base_path('tests/Unit/Domain/'.SAMPLING_STATE_CONTEXT."/{$aggregate}/{$aggregate}EntityTest.php");
}

function forgetSamplingState(): void
{
    File::deleteDirectory(app_path('Domain/'.SAMPLING_STATE_CONTEXT));
    File::deleteDirectory(base_path('tests/Unit/Domain/'.SAMPLING_STATE_CONTEXT));
}

/**
 * The root of an aggregate, its child and a status, as the generators write them.
 */
function samplingStateAggregate(string $aggregate): void
{
    $domain = SAMPLING_STATE_CONTEXT."/{$aggregate}";

    test()->artisan('make:entity', ['name' => $aggregate, '--domain' => $domain])->assertSuccessful();
    test()->artisan('make:entity', ['name' => "{$aggregate}Lid", '--domain' => $domain, '--child' => true])->assertSuccessful();
    test()->artisan('make:enum', ['name' => "{$aggregate}Status", '--domain' => $domain, '--string' => true, '--case' => ['Open', 'Sealed']])->assertSuccessful();
}

beforeEach(fn () => forgetSamplingState());

afterEach(fn () => forgetSamplingState());

describe('make:entity-state', function () {
    it('promotes each property in the constructor, takes it in reconstitute() and reads it through a getter, leaving create() alone', function () {
        samplingStateAggregate('Crate');
        $before = File::get(samplingStatePath('Crate'));

        $this->artisan('make:entity-state', [
            'entity' => 'Crate',
            '--domain' => SAMPLING_STATE_CONTEXT.'/Crate',
            '--field' => ['status:CrateStatus', 'price:?Shared/Money', 'code:string|int'],
        ])
            ->expectsOutputToContain('create() still builds new self(…) without status, price, code')
            ->assertSuccessful();

        $namespace = 'App\Domain\\'.SAMPLING_STATE_CONTEXT.'\Crate';
        $code = File::get(samplingStatePath('Crate'));
        $create = fn (string $code): string => substr($code, (int) strpos($code, 'public static function create('), (int) strpos($code, 'public static function reconstitute(') - (int) strpos($code, 'public static function create('));

        expect($code)
            ->toContain("use {$namespace}\\Enums\\CrateStatus;")
            ->toContain('use App\Domain\Shared\ValueObjects\Money;')
            ->toContain("        private string \$id,\n        private CrateStatus \$status,\n        private ?Money \$price,\n        private string|int \$code,\n        // define attribute here\n    ) {}")
            ->toContain("    public static function reconstitute(\n        string \$id,\n        CrateStatus \$status,\n        ?Money \$price,\n        string|int \$code,\n    ): self {\n        return new self(\n            id: \$id,\n            status: \$status,\n            price: \$price,\n            code: \$code,\n        );\n    }")
            ->toContain("    public function status(): CrateStatus\n    {\n        return \$this->status;\n    }")
            ->toContain("    public function code(): string|int\n    {\n        return \$this->code;\n    }")
            ->and($create($code))->toBe($create($before))
            ->and(strpos($code, 'function status()'))->toBeGreaterThan((int) strpos($code, 'function id()'))
            ->and(strpos($code, 'function code()'))->toBeLessThan((int) strpos($code, 'Asserting Section'))
            ->and(File::get(samplingStateTestPath('Crate')))
            ->toContain("        it('carries status')->todo();\n\n        it('carries price')->todo();\n\n        it('carries code')->todo();\n    });");

        $pint = Process::path(base_path())->run([base_path('vendor/bin/pint'), '--test', samplingStatePath('Crate')]);

        expect($pint->successful())->toBeTrue($pint->output());
    });

    it('adds state to a child, after what it already holds, and the reader reads it back in order', function () {
        samplingStateAggregate('Bin');
        $domain = SAMPLING_STATE_CONTEXT.'/Bin';

        $this->artisan('make:entity-state', ['entity' => 'BinLid', '--domain' => $domain, '--field' => ['open:bool']])->assertSuccessful();
        $this->artisan('make:entity-state', ['entity' => 'BinLid', '--domain' => $domain, '--field' => ['status:BinStatus']])->assertSuccessful();

        $reader = new StructureReader(base_path());

        expect($reader->read(SAMPLING_STATE_CONTEXT)['entities']['BinLid'])->toBe([
            'aggregate' => 'Bin',
            'state' => ['open' => 'bool', 'status' => 'BinStatus'],
            'behaviours' => [],
            'assertions' => [],
        ])
            ->and($reader->stateWithoutGetter(SAMPLING_STATE_CONTEXT))->toBe([]);
    });

    it('refuses state the entity already holds, its id, a type that names no class, and an entity not built yet', function (array $arguments, string $refusal) {
        samplingStateAggregate('Box');
        $this->artisan('make:entity-state', ['entity' => 'Box', '--domain' => SAMPLING_STATE_CONTEXT.'/Box', '--field' => ['status:BoxStatus']])->assertSuccessful();
        $before = File::get(samplingStatePath('Box'));

        $this->artisan('make:entity-state', [...['entity' => 'Box', '--domain' => SAMPLING_STATE_CONTEXT.'/Box'], ...$arguments])
            ->expectsOutputToContain($refusal)
            ->assertFailed();

        expect(File::get(samplingStatePath('Box')))->toBe($before);
    })->with([
        'held already' => [['--field' => ['status:BoxStatus']], 'BoxEntity already holds status.'],
        'the id' => [['--field' => ['id:string']], 'Every entity already holds its id.'],
        'twice' => [['--field' => ['size:int', 'size:int']], 'The field size is given twice.'],
        'no class' => [['--field' => ['grade:BoxGrade']], 'names no class yet'],
        'no name' => [['--field' => ['Size:int']], 'is not name:Type with a camelCase name'],
        'nothing' => [['--field' => []], 'Name the state to add with --field=name:Type.'],
        'not built' => [['entity' => 'Lid'], 'LidEntity is not built yet'],
    ]);

    it('refuses an entity whose reconstitute() is not laid out the way make:entity writes it', function () {
        samplingStateAggregate('Tub');
        File::put(samplingStatePath('Tub'), str_replace("    public static function reconstitute(\n        string \$id,\n    ): self {", '    public static function reconstitute(string $id): self {', File::get(samplingStatePath('Tub'))));

        $this->artisan('make:entity-state', ['entity' => 'Tub', '--domain' => SAMPLING_STATE_CONTEXT.'/Tub', '--field' => ['size:int']])
            ->expectsOutputToContain('TubEntity has no reconstitute() laid out the way make:entity writes it — add the state by hand.')
            ->assertFailed();
    });
});
