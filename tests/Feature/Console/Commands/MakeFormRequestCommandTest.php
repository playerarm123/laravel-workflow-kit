<?php

use App\Http\Requests\SamplingTote\SamplingToteFormValues;
use App\Http\Requests\SamplingTote\StoreSamplingToteRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\PendingCommand;

/**
 * โฟลเดอร์ทดลองของไฟล์นี้ ห้ามซ้ำกับไฟล์เทสต์ generator ตัวอื่น มิฉะนั้น --parallel
 * จะลบของกันเองกลางคัน
 */
const FORM_REQUEST_CONTEXT = 'SamplingWarehousing';

/**
 * @param  array<string, mixed>  $arguments
 */
function runMakeFormRequest(array $arguments = []): PendingCommand
{
    return test()->artisan('make:form-request', ['name' => 'SamplingTote', '--domain' => FORM_REQUEST_CONTEXT, ...$arguments]);
}

function formRequestGenerated(string $class): string
{
    return app_path("Http/Requests/SamplingTote/{$class}.php");
}

/**
 * The Commands a filled-in `make:use-case CreateSamplingTote` and `UpdateSamplingTote` would hold.
 *
 * A class loads once per process, so the update Command is written once and a case without
 * one names a use case that does not exist instead of deleting it.
 */
function writeFormRequestFixtures(): void
{
    $namespace = 'App\\Application\\'.FORM_REQUEST_CONTEXT;

    $fixtures = [
        'SamplingToteSize' => <<<PHP
<?php

namespace {$namespace};

enum SamplingToteSize: string
{
    case Small = 'small';
    case Large = 'large';
}
PHP,
        'UseCases/CreateSamplingTote/CreateSamplingToteCommand' => <<<PHP
<?php

namespace {$namespace}\\UseCases\\CreateSamplingTote;

use {$namespace}\\SamplingToteSize;
use Illuminate\\Http\\UploadedFile;
use Spatie\\LaravelData\\Data;

final class CreateSamplingToteCommand extends Data
{
    public function __construct(
        public readonly string \$label,
        public readonly ?string \$note,
        public readonly int \$slots,
        public readonly bool \$fragile,
        public readonly SamplingToteSize \$size,
        public readonly ?UploadedFile \$photo,
    ) {}
}
PHP,
        'UseCases/UpdateSamplingTote/UpdateSamplingToteCommand' => <<<PHP
<?php

namespace {$namespace}\\UseCases\\UpdateSamplingTote;

use {$namespace}\\SamplingToteSize;
use Spatie\\LaravelData\\Data;

final class UpdateSamplingToteCommand extends Data
{
    public function __construct(
        public readonly string \$samplingToteId,
        public readonly string \$label,
        public readonly ?string \$note,
        public readonly SamplingToteSize \$size,
    ) {}
}
PHP,
    ];

    foreach ($fixtures as $relative => $contents) {
        $path = app_path('Application/'.FORM_REQUEST_CONTEXT."/{$relative}.php");

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
    }
}

function cleanFormRequestScratch(): void
{
    File::deleteDirectory(app_path('Application/'.FORM_REQUEST_CONTEXT));
    File::deleteDirectory(app_path('Http/Requests/SamplingTote'));
}

beforeEach(function () {
    cleanFormRequestScratch();
    writeFormRequestFixtures();
});

afterEach(function () {
    cleanFormRequestScratch();
});

it('fails and points at make:use-case when the create Command does not exist', function () {
    runMakeFormRequest(['name' => 'SamplingHamper'])
        ->expectsOutputToContain('make:use-case CreateSamplingHamper')
        ->assertFailed();

    expect(File::isDirectory(app_path('Http/Requests/SamplingHamper')))->toBeFalse();
});

it('writes one rule per argument of the create Command, guessed from its type', function () {
    runMakeFormRequest()
        ->expectsOutputToContain('CreateSamplingToteCommand::$photo is a file')
        ->assertSuccessful();

    expect(File::get(formRequestGenerated('ValidatesSamplingTote')))
        ->toContain('trait ValidatesSamplingTote')
        ->toContain("'label' => ['required', 'string', 'max:255'],")
        ->toContain("'note' => ['nullable', 'string', 'max:255'],")
        ->toContain("'slots' => ['required', 'integer'],")
        ->toContain("'fragile' => ['required', 'boolean'],")
        ->toContain("'size' => ['required', Rule::enum(SamplingToteSize::class)],")
        ->toContain("'photo' => ['nullable', 'file'],")
        ->toContain("'label' => __('sampling-totes.label'),")
        ->toContain('protected function uploadedFile(string $key): UploadedFile')
        ->not->toContain('{{');
});

it('hands the create Command over from toCommand() with named arguments', function () {
    runMakeFormRequest()->assertSuccessful();

    expect(File::get(formRequestGenerated('StoreSamplingToteRequest')))
        ->toContain('use ValidatesSamplingTote;')
        ->toContain("return Gate::allows('create', SamplingTote::class);")
        ->toContain('public function toCommand(): CreateSamplingToteCommand')
        ->toContain('return new CreateSamplingToteCommand(')
        ->toContain("label: \$this->string('label')->toString(),")
        ->toContain("note: \$this->filled('note') ? \$this->string('note')->toString() : null,")
        ->toContain("slots: \$this->integer('slots'),")
        ->toContain("fragile: \$this->boolean('fragile'),")
        ->toContain("size: SamplingToteSize::from(\$this->string('size')->toString()),")
        ->toContain("photo: \$this->hasFile('photo') ? \$this->uploadedFile('photo') : null,")
        ->not->toContain('::from([');
});

it('reads the id of the row it changes from the bound model', function () {
    runMakeFormRequest()->assertSuccessful();

    expect(File::get(formRequestGenerated('UpdateSamplingToteRequest')))
        ->toContain("return Gate::allows('update', \$this->samplingTote());")
        ->toContain('public function toCommand(): UpdateSamplingToteCommand')
        ->toContain('samplingToteId: $this->samplingTote()->id,')
        ->toContain("\$samplingTote = \$this->route('sampling_tote');")
        ->toContain('private function samplingTote(): SamplingTote');
});

it('writes form values whose keys are the rules', function () {
    runMakeFormRequest()->assertSuccessful();

    require_once formRequestGenerated('ValidatesSamplingTote');
    require_once formRequestGenerated('StoreSamplingToteRequest');
    require_once formRequestGenerated('SamplingToteFormValues');

    $rules = array_keys((new StoreSamplingToteRequest)->rules());
    $values = SamplingToteFormValues::empty()->toArray();

    expect(array_keys($values))->toBe($rules)
        ->and($values)->toBe([
            'label' => '',
            'note' => '',
            'slots' => 0,
            'fragile' => false,
            'size' => '',
            'photo' => null,
        ])
        ->and(File::get(formRequestGenerated('SamplingToteFormValues')))
        ->toContain('final class SamplingToteFormValues implements Arrayable')
        ->toContain('@return array{label: string, note: string, slots: int, fragile: bool, size: string, photo: null}')
        ->toContain('public static function of(SamplingTote $samplingTote): self');
});

it('writes PHP that pint leaves as it is', function () {
    runMakeFormRequest()->assertSuccessful();

    $pint = Process::path(base_path())->run([base_path('vendor/bin/pint'), '--test', app_path('Http/Requests/SamplingTote')]);

    expect($pint->successful())->toBeTrue($pint->output());
});

it('skips the update request when there is no update Command', function () {
    runMakeFormRequest(['--update' => 'ReshapeSamplingTote'])
        ->expectsOutputToContain('no UpdateSamplingToteRequest was written')
        ->assertSuccessful();

    expect(File::exists(formRequestGenerated('UpdateSamplingToteRequest')))->toBeFalse()
        ->and(File::exists(formRequestGenerated('StoreSamplingToteRequest')))->toBeTrue();
});

it('never overwrites a request that already exists', function () {
    File::ensureDirectoryExists(app_path('Http/Requests/SamplingTote'));
    File::put(formRequestGenerated('StoreSamplingToteRequest'), 'hand written');

    runMakeFormRequest()
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect(File::get(formRequestGenerated('StoreSamplingToteRequest')))->toBe('hand written');
});

it('reports the model and the translation keys the requests still need', function () {
    runMakeFormRequest()
        ->expectsOutputToContain('Model [App\Models\SamplingTote] does not exist')
        ->expectsOutputToContain('sampling-totes.label')
        ->assertSuccessful();
});
