<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\PendingCommand;

/** The Architecture rule helpers. */
require_once dirname(__DIR__, 3).'/Architecture/Support/rules.php';

/**
 * This file's scratch aggregate name must not match any other generator test file's, or --parallel
 * runs delete each other's files midway.
 */
const FORM_PAGE_SUBJECT = 'SamplingPallet';

/**
 * @param  array<string, mixed>  $arguments
 */
function runMakeFormPage(array $arguments = []): PendingCommand
{
    return test()->artisan('make:form-page', ['name' => FORM_PAGE_SUBJECT, ...$arguments]);
}

/**
 * The generated files, relative to the project root as the rules helpers read them.
 *
 * @return array{types: string, form: string, create: string, edit: string, create_browser: string, edit_browser: string}
 */
function formPageGenerated(): array
{
    return [
        'types' => 'resources/js/types/sampling-pallet.ts',
        'form' => 'resources/js/components/sampling-pallet/form.tsx',
        'create' => 'resources/js/pages/sampling-pallets/create.tsx',
        'edit' => 'resources/js/pages/sampling-pallets/edit.tsx',
        'create_browser' => 'tests/Browser/SamplingPallets/CreateTest.php',
        'edit_browser' => 'tests/Browser/SamplingPallets/EditTest.php',
    ];
}

/**
 * The form values a filled-in `make:form-request SamplingPallet` would hold.
 */
function writeFormPageFixture(): void
{
    $path = app_path('Http/Requests/SamplingPallet/SamplingPalletFormValues.php');

    File::ensureDirectoryExists(dirname($path));
    File::put($path, <<<'PHP'
<?php

namespace App\Http\Requests\SamplingPallet;

final class SamplingPalletFormValues
{
    /**
     * @return array{label: string, note: string|null, slots: int, stackable: bool, photo: null, tags: list<string>}
     */
    public function toArray(): array
    {
        return ['label' => '', 'note' => null, 'slots' => 0, 'stackable' => false, 'photo' => null, 'tags' => []];
    }
}
PHP);
}

function cleanFormPageScratch(): void
{
    File::deleteDirectory(app_path('Http/Requests/SamplingPallet'));
    File::delete(array_map(base_path(...), formPageGenerated()));
    File::deleteDirectory(resource_path('js/components/sampling-pallet'));
    File::deleteDirectory(resource_path('js/pages/sampling-pallets'));
    File::deleteDirectory(base_path('tests/Browser/SamplingPallets'));

    rewriteSharedFile(resource_path('js/types/index.ts'), fn (string $barrel): string => str_replace("export type * from './sampling-pallet';\n", '', $barrel));
}

beforeEach(function () {
    cleanFormPageScratch();
    writeFormPageFixture();
});

afterEach(function () {
    cleanFormPageScratch();
});

it('fails and points at make:form-request when there are no form values yet', function () {
    runMakeFormPage(['name' => 'SamplingTrolley'])
        ->expectsOutputToContain('make:form-request SamplingTrolley')
        ->assertFailed();

    expect(File::exists(resource_path('js/pages/sampling-trolleys/create.tsx')))->toBeFalse();
});

it('mirrors the form values key for key in TypeScript', function () {
    runMakeFormPage()
        ->expectsOutputToContain('Field "tags" is `unknown`')
        ->assertSuccessful();

    expect(File::get(base_path(formPageGenerated()['types'])))
        ->toContain('@see App\Http\Requests\SamplingPallet\SamplingPalletFormValues')
        ->toContain('export type SamplingPalletFormValues = {')
        ->toContain('note: string | null;')
        ->toContain('stackable: boolean;')
        ->toContain('photo: null;')
        ->and(ruleTsTypeKeys(formPageGenerated()['types'], 'SamplingPalletFormValues'))
        ->toBe(['label', 'note', 'slots', 'stackable', 'photo', 'tags'])
        ->and(File::get(resource_path('js/types/index.ts')))->toContain("export type * from './sampling-pallet';");
});

it('writes one uncontrolled input per key inside <Form>', function () {
    runMakeFormPage()->assertSuccessful();

    expect(File::get(base_path(formPageGenerated()['form'])))
        ->toContain('export function SamplingPalletForm({')
        ->toContain('<Form {...action}')
        ->toContain('defaultValue={defaults.label}')
        ->toContain("defaultValue={defaults.note ?? ''}")
        ->toContain('type="number"')
        ->toContain('type="file"')
        ->toMatch('/type="hidden"\s+name="stackable"\s+value="0"/')
        ->toContain('useState<boolean>(defaults.stackable)')
        ->toContain('<InputError message={errors.label} />')
        ->toContain("t('sampling-pallets.slots')")
        ->not->toContain('useForm')
        ->not->toContain('{{');
});

it('writes create and edit shells that pass the server defaults to the form', function () {
    runMakeFormPage()->assertSuccessful();

    expect(File::get(base_path(formPageGenerated()['create'])))
        ->toContain("import { SamplingPalletForm } from '@/components/sampling-pallet/form';")
        ->toContain('defaults: SamplingPalletFormValues;')
        ->toContain('SamplingPalletController.store.form()')
        ->toContain("import { create, index } from '@/routes/sampling-pallets';")
        ->and(File::get(base_path(formPageGenerated()['edit'])))
        ->toContain('samplingPallet: { id: string };')
        ->toMatch('/SamplingPalletController\.update\.form\(\s*samplingPallet,?\s*\)/')
        ->toContain('cancelHref={index()}')
        ->toContain("import { edit, index } from '@/routes/sampling-pallets';")
        ->not->toContain('show(');
});

it('sends the edit page back to the show page only when the resource has one', function () {
    $manifest = base_path('.kit/structure/http/'.FORM_PAGE_SUBJECT.'.json');
    File::put($manifest, (string) json_encode([
        'resource' => FORM_PAGE_SUBJECT, 'model' => FORM_PAGE_SUBJECT, 'controller' => ['show' => [], 'edit' => []], 'actions' => [], 'policy' => null, 'pages' => [],
    ]));

    try {
        expect(Artisan::call('make:form-page', ['name' => FORM_PAGE_SUBJECT]))->toBe(0)
            ->and(Artisan::output())->toContain('sampling-pallets.show');
    } finally {
        File::delete($manifest);
    }

    expect(File::get(base_path(formPageGenerated()['edit'])))
        ->toContain('cancelHref={show(samplingPallet)}')
        ->toContain('<Link href={show(samplingPallet)}>')
        ->toContain("import { edit, index, show } from '@/routes/sampling-pallets';");
});

it('writes files the form-pages ESLint rules and import order accept', function () {
    runMakeFormPage()->assertSuccessful();

    $files = array_map(base_path(...), [formPageGenerated()['form'], formPageGenerated()['create'], formPageGenerated()['edit']]);
    $eslint = Process::path(base_path())->run([base_path('node_modules/.bin/eslint'), '--no-ignore', ...$files]);

    expect($eslint->successful())->toBeTrue($eslint->output());
});

it('writes the browser tests of both pages at the paths the testing rule mirrors', function () {
    runMakeFormPage()->assertSuccessful();

    expect(File::get(base_path(formPageGenerated()['create_browser'])))
        ->toContain('resources/js/pages/sampling-pallets/create.tsx')
        ->toContain("it('saves and lands with the success toast')->todo();")
        ->not->toContain('{{')
        ->and(File::get(base_path(formPageGenerated()['edit_browser'])))
        ->toContain('resources/js/pages/sampling-pallets/edit.tsx')
        ->toContain("it('saves the change and lands with the success toast')->todo();")
        ->not->toContain('{{');
});

it('never overwrites a browser test that already exists', function () {
    File::ensureDirectoryExists(base_path('tests/Browser/SamplingPallets'));
    File::put(base_path(formPageGenerated()['edit_browser']), 'hand written');

    runMakeFormPage()
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect(File::get(base_path(formPageGenerated()['edit_browser'])))->toBe('hand written')
        ->and(File::exists(base_path(formPageGenerated()['create_browser'])))->toBeTrue();
});

it('never overwrites a form or a page that already exists', function () {
    File::ensureDirectoryExists(resource_path('js/components/sampling-pallet'));
    File::put(base_path(formPageGenerated()['form']), 'hand written');

    runMakeFormPage()
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect(File::get(base_path(formPageGenerated()['form'])))->toBe('hand written');
});

it('reports the routes and translation keys the pages still need', function () {
    expect(Artisan::call('make:form-page', ['name' => FORM_PAGE_SUBJECT]))->toBe(0)
        ->and(Artisan::output())
        ->toContain('Routes [sampling-pallets.index, sampling-pallets.create')
        ->toContain('lang/en.json is missing')
        ->toContain('sampling-pallets.create_title');
});
