<?php

use App\Domain\Shared\AggregateRoot;
use App\Domain\Shared\DomainEntity;
use Illuminate\Support\Facades\File;

/**
 * This file's scratch folder must not match any other generator test file's, or --parallel
 * runs delete each other's files midway.
 */
const SAMPLING_ENTITY_CONTEXT = 'SamplingEntity';

function entityPath(string $class, string $domain = SAMPLING_ENTITY_CONTEXT.'/Sample'): string
{
    return app_path("Domain/{$domain}/{$class}.php");
}

function entityTestPath(string $class, string $domain = SAMPLING_ENTITY_CONTEXT.'/Sample'): string
{
    return base_path("tests/Unit/Domain/{$domain}/{$class}Test.php");
}

beforeEach(function () {
    File::deleteDirectory(app_path('Domain/'.SAMPLING_ENTITY_CONTEXT));
    File::deleteDirectory(base_path('tests/Unit/Domain/'.SAMPLING_ENTITY_CONTEXT));
});

afterEach(function () {
    File::deleteDirectory(app_path('Domain/'.SAMPLING_ENTITY_CONTEXT));
    File::deleteDirectory(base_path('tests/Unit/Domain/'.SAMPLING_ENTITY_CONTEXT));
});

it('generates the entity under the given aggregate', function () {
    $this->artisan('make:entity', ['name' => 'Sample', '--domain' => 'SamplingEntity/Sample'])
        ->assertSuccessful();

    expect(File::get(entityPath('SampleEntity')))
        ->toContain('namespace App\Domain\SamplingEntity\Sample;')
        ->toContain('use App\Domain\Shared\AggregateRoot;')
        ->toContain('final class SampleEntity extends AggregateRoot')
        ->not->toContain('{{');
});

it('generates a child entity in the aggregate entities folder', function () {
    $this->artisan('make:entity', ['name' => 'SampleLine', '--domain' => 'SamplingEntity/Sample', '--child' => true])
        ->assertSuccessful();

    expect(File::get(entityPath('Entities/SampleLineEntity')))
        ->toContain('namespace App\Domain\SamplingEntity\Sample\Entities;')
        ->toContain('use App\Domain\Shared\DomainEntity;')
        ->toContain('final class SampleLineEntity extends DomainEntity')
        ->not->toContain('{{')
        ->and(File::exists(entityPath('SampleLineEntity')))->toBeFalse();
});

it('generates a root and a child that load and build as written', function () {
    $this->artisan('make:entity', ['name' => 'Loadable', '--domain' => 'SamplingEntity/Sample'])->assertSuccessful();
    $this->artisan('make:entity', ['name' => 'LoadableLine', '--domain' => 'SamplingEntity/Sample', '--child' => true])->assertSuccessful();

    $root = 'App\Domain\SamplingEntity\Sample\LoadableEntity';
    $child = 'App\Domain\SamplingEntity\Sample\Entities\LoadableLineEntity';

    expect(is_subclass_of($root, AggregateRoot::class))->toBeTrue()
        ->and($root::entityName())->toBe('Loadable')
        ->and($root::create('root-id')->id())->toBe('root-id')
        ->and(is_subclass_of($child, DomainEntity::class))->toBeTrue()
        ->and(is_subclass_of($child, AggregateRoot::class))->toBeFalse()
        ->and($child::entityName())->toBe('Loadable Line')
        ->and($child::reconstitute('child-id')->id())->toBe('child-id');
});

it('writes a unit test mirroring where the root and the child land', function () {
    $this->artisan('make:entity', ['name' => 'Sample', '--domain' => 'SamplingEntity/Sample'])->assertSuccessful();
    $this->artisan('make:entity', ['name' => 'SampleLine', '--domain' => 'SamplingEntity/Sample', '--child' => true])->assertSuccessful();

    expect(File::get(entityTestPath('SampleEntity')))
        ->toContain('use App\Domain\SamplingEntity\Sample\SampleEntity;')
        ->toContain("describe('SampleEntity'")
        ->toContain("describe('create()'")
        ->toContain('->todo();')
        ->not->toContain('{{')
        ->and(File::get(entityTestPath('Entities/SampleLineEntity')))
        ->toContain('use App\Domain\SamplingEntity\Sample\Entities\SampleLineEntity;');
});

it('never overwrites a test that already exists', function () {
    File::ensureDirectoryExists(dirname(entityTestPath('SampleEntity')));
    File::put(entityTestPath('SampleEntity'), '<?php // hand written');

    $this->artisan('make:entity', ['name' => 'Sample', '--domain' => 'SamplingEntity/Sample'])
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect(File::get(entityTestPath('SampleEntity')))->toBe('<?php // hand written');
});

it('strips entity decoration from the given name', function () {
    $this->artisan('make:entity', ['name' => 'SampleEntity', '--domain' => 'SamplingEntity/Sample'])
        ->assertSuccessful();

    expect(File::exists(entityPath('SampleEntity')))->toBeTrue()
        ->and(File::exists(entityPath('SampleEntityEntity')))->toBeFalse();
});

it('asks which aggregate owns the entity when no domain is given', function () {
    seedSamplingAggregate(SAMPLING_ENTITY_CONTEXT);

    $this->artisan('make:entity', ['name' => 'Widget'])
        ->expectsQuestion('Which domain owns this entity?', 'SamplingEntity/Sample')
        ->assertSuccessful();

    expect(File::exists(entityPath('WidgetEntity')))->toBeTrue();
});

it('offers the aggregates that already hold a root entity', function () {
    seedSamplingAggregate(SAMPLING_ENTITY_CONTEXT);
    File::ensureDirectoryExists(app_path('Domain/SamplingEntity/Empty'));

    expect(domainChoicesOf('make:entity'))
        ->toContain('SamplingEntity/Sample')
        ->not->toContain('SamplingEntity/Empty')
        ->not->toContain('SamplingEntity');
});

it('asks for an aggregate that does not exist yet', function () {
    $this->artisan('make:entity', ['name' => 'Sample'])
        ->expectsQuestion('Which domain owns this entity?', '__another__')
        ->expectsQuestion('Which domain owns this entity?', 'samplingEntity/sample')
        ->assertSuccessful();

    expect(File::get(entityPath('SampleEntity')))
        ->toContain('namespace App\Domain\SamplingEntity\Sample;');
});

it('fails instead of guessing the domain when it cannot ask', function () {
    $this->artisan('make:entity', ['name' => 'Sample', '--no-interaction' => true])
        ->expectsOutputToContain('Pass --domain to name the domain that owns the entity.')
        ->assertFailed();

    expect(File::exists(entityPath('SampleEntity')))->toBeFalse();
});

it('refuses to overwrite an existing entity', function () {
    $this->artisan('make:entity', ['name' => 'Sample', '--domain' => 'SamplingEntity/Sample']);

    File::put(entityPath('SampleEntity'), '<?php // hand written');

    $this->artisan('make:entity', ['name' => 'Sample', '--domain' => 'SamplingEntity/Sample'])
        ->assertFailed();

    expect(File::get(entityPath('SampleEntity')))->toBe('<?php // hand written');
});

it('never offers the shared kernel as a domain', function () {
    seedSamplingAggregate(SAMPLING_ENTITY_CONTEXT);

    expect(File::glob(app_path('Domain/Shared/*Entity.php')))->not->toBeEmpty();

    expect(domainChoicesOf('make:entity'))
        ->toContain('SamplingEntity/Sample')
        ->not->toContain('Shared')
        ->and(array_filter(domainChoicesOf('make:entity'), fn (string $domain) => str_starts_with($domain, 'Shared/')))
        ->toBeEmpty();
});
