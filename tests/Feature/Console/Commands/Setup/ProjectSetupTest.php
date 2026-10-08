<?php

use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Setup\ProjectSetup;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;

/**
 * kit:setup's steps on a copy of the files Laravel's React starter kit ships
 * (tests/Fixtures/StarterKit), so every edit is proven against the code it edits. The whole run,
 * dependencies and checks included, is proven by the kit's CI on a project `laravel new` makes.
 */
function setupProjectCopy(): string
{
    $path = storage_path('framework/testing/kit-setup-'.bin2hex(random_bytes(4)));
    File::copyDirectory(dirname(__DIR__, 4).'/Fixtures/StarterKit', $path);

    return $path;
}

/**
 * @return array<string, array{changed: list<string>, manual: list<string>}>
 */
function runProjectSetup(ProjectSetup $setup): array
{
    return [
        'composer' => $setup->composerDependencies(),
        'npm' => $setup->npmDependencies(),
        'files' => $setup->files(),
        'config' => $setup->config(),
        'wiring' => $setup->wiring(),
        'users' => $setup->users(),
        'audit' => $setup->auditPage(),
    ];
}

beforeEach(function () {
    $this->project = setupProjectCopy();
    $this->setup = new ProjectSetup(WorkflowKit::kitPath(), $this->project, 'pgsql');
    $this->read = fn (string $relative): string => (string) file_get_contents($this->project.'/'.$relative);
});

afterEach(function () {
    File::deleteDirectory($this->project);
});

it('leaves nothing to do by hand on the starter kit', function () {
    $results = runProjectSetup($this->setup);

    expect(array_merge(...array_column($results, 'manual')))->toBe([]);
});

it('changes nothing on a second run', function () {
    runProjectSetup($this->setup);

    $second = runProjectSetup(new ProjectSetup(WorkflowKit::kitPath(), $this->project, 'pgsql'));

    expect(array_merge(...array_column($second, 'changed')))->toBe([]);
});

describe('composerDependencies', function () {
    it('swaps PHPUnit for Pest and declares what the stack requires, php first', function () {
        $result = $this->setup->composerDependencies();
        $composer = json_decode(($this->read)('composer.json'), true);

        expect(array_key_first($composer['require']))->toBe('php')
            ->and($composer['require']['php'])->toBe('^8.4')
            ->and($composer['require'])->toHaveKeys(['laravel/nightwatch', 'spatie/laravel-data', 'league/flysystem-aws-s3-v3'])
            ->and($composer['require-dev'])->toHaveKeys(['pestphp/pest', 'pestphp/pest-plugin-laravel', 'pestphp/pest-plugin-browser', 'laravel/boost'])
            ->and($composer['require-dev'])->not->toHaveKey('phpunit/phpunit')
            ->and($result['packages'])->toContain('phpunit/phpunit', 'pestphp/pest');
    });
});

describe('npmDependencies', function () {
    it('swaps the @radix-ui packages for radix-ui and adds the lint and format scripts', function () {
        $this->setup->npmDependencies();
        $package = json_decode(($this->read)('package.json'), true);
        $declared = [...array_keys($package['dependencies']), ...array_keys($package['devDependencies'])];

        expect(array_filter($declared, fn (string $name): bool => str_starts_with($name, '@radix-ui/')))->toBe([])
            ->and($declared)->toContain('radix-ui', 'eslint', 'prettier', '@tanstack/react-table')
            ->and($package['dependencies']['lucide-react'])->toBe('^1.45.0')
            ->and($package['scripts'])->toHaveKeys(['lint', 'lint:check', 'format', 'format:check']);
    });
});

describe('files', function () {
    it('writes the kit files, the scaffolds and an empty rule-overrides.json, and drops the examples', function () {
        $this->setup->files();

        expect($this->project.'/app/Http/ExceptionResponses.php')->toBeFile()
            ->and($this->project.'/app/Infra/Persistence/Uuid7IdGenerator.php')->toBeFile()
            ->and($this->project.'/tests/Pest.php')->toBeFile()
            ->and($this->project.'/resources/js/components/ui/table.tsx')->toBeFile()
            ->and(json_decode(($this->read)('rule-overrides.json'), true))->toBe(['overrides' => []])
            ->and($this->project.'/tests/Unit/ExampleTest.php')->not->toBeFile();
    });
});

describe('files on a starter kit made with Pest', function () {
    it('completes the Pest.php the preset ships and keeps the rest of it', function (string $preset) {
        copy(dirname(__DIR__, 4).'/Fixtures/PestPresets/'.$preset, $this->project.'/tests/Pest.php');

        $result = $this->setup->files();
        $pest = ($this->read)('tests/Pest.php');

        expect($result['manual'])->toBe([])
            ->and($pest)->toContain("pest()->extend(TestCase::class)\n    ->use(RefreshDatabase::class)\n    ->in('Feature', 'Browser');")
            ->and($pest)->toContain('use Illuminate\Foundation\Testing\RefreshDatabase;', 'use App\Models\User;', 'function auditLogReader(): User', 'function auditLogOutsider(): User', "expect()->extend('toBeOne'", 'function something()')
            ->and($pest)->not->toContain("->in('Feature');");
    })->with(['pest-init.php', 'uses.php']);

    it('changes nothing in it on a second run', function () {
        copy(dirname(__DIR__, 4).'/Fixtures/PestPresets/pest-init.php', $this->project.'/tests/Pest.php');
        $this->setup->files();

        expect((new ProjectSetup(WorkflowKit::kitPath(), $this->project, 'pgsql'))->files()['changed'])->not->toContain('tests/Pest.php');
    });

    it('adds the helpers and leaves a binding it cannot find to the user', function () {
        file_put_contents($this->project.'/tests/Pest.php', "<?php\n\nrequire __DIR__.'/bootstrap.php';\n");

        $result = $this->setup->files();

        expect($result['manual'])->toHaveCount(1)
            ->and($result['manual'][0])->toStartWith("tests/Pest.php: bind TestCase with RefreshDatabase to 'Feature' and 'Browser'")
            ->and(($this->read)('tests/Pest.php'))->toContain("require __DIR__.'/bootstrap.php';", 'function auditLogReader(): User');
    });
});

describe('config', function () {
    it('sets the chosen engine in .env.example and phpunit.xml and adds the Architecture suite', function () {
        $this->setup->config();

        expect(($this->read)('.env.example'))->toContain("\n\nDB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_PORT=5432", 'NIGHTWATCH_TOKEN=')
            ->and(($this->read)('phpunit.xml'))->toContain('<env name="DB_CONNECTION" value="pgsql"/>', 'vendor/playerarm123/laravel-workflow-kit/tests/Architecture')
            ->and(($this->read)('phpstan.neon'))->toContain('tests/PHPStan/write-path.php')
            ->and(($this->read)('config/app.php'))->toContain("'currency' => env('APP_CURRENCY', 'USD')")
            ->and(($this->read)('vite.config.ts'))->toContain("'resources/js/app.tsx', 'resources/js/kit/structure.tsx'");
    });

    it('sets mysql with its own port and user', function () {
        (new ProjectSetup(WorkflowKit::kitPath(), $this->project, 'mysql'))->config();

        expect(($this->read)('.env.example'))->toContain("DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306", 'DB_USERNAME=root');
    });
});

describe('wiring', function () {
    it('wires the providers, the exception responses, the actor and the shared props', function () {
        $this->setup->wiring();

        expect(($this->read)('bootstrap/providers.php'))->toContain('use App\Infra\Persistence\PersistenceServiceProvider;', '    KitServiceProvider::class,')
            ->and(($this->read)('bootstrap/app.php'))->toContain('ExceptionResponses::register($exceptions);', "            HandleInertiaRequests::class,\n            InitializeUserContext::class,")
            ->and(($this->read)('app/Providers/AppServiceProvider.php'))->toContain('Inertia::handleExceptionsUsing(ExceptionResponses::respond(...));')
            ->and(($this->read)('app/Http/Middleware/HandleInertiaRequests.php'))->toContain("'translations' => \$this->translations(),", 'private function messagesFor(string $locale): array')
            ->and(($this->read)('resources/js/types/global.d.ts'))->toContain('sharedPageProps: SharedProps;')
            ->and(($this->read)('resources/js/types/index.ts'))->toContain("export type * from './shared';")
            ->and(($this->read)('resources/js/app.tsx'))->toContain("<FlashToast />\n                <Toaster />")
            ->and(($this->read)('resources/js/components/ui/sonner.tsx'))->not->toContain('useFlashToast');
    });

    it('leaves a file it cannot read the way it expects to the user', function () {
        file_put_contents($this->project.'/bootstrap/app.php', "<?php\n\nreturn require 'elsewhere.php';\n");

        expect($this->setup->wiring()['manual'])->toContain('bootstrap/app.php: call ExceptionResponses::register($exceptions) in withExceptions() and append InitializeUserContext to the web middleware')
            ->and(($this->read)('bootstrap/app.php'))->toBe("<?php\n\nreturn require 'elsewhere.php';\n");
    });
});

describe('users', function () {
    it('keys users on a uuid, and their sessions and passkeys on it', function () {
        $this->setup->users();
        $migration = ($this->read)('database/migrations/0001_01_01_000000_create_users_table.php');

        expect($migration)->toContain("\$table->uuid('id')->primary();", "\$table->foreignUuid('user_id')->nullable()->index()->constrained('users')->cascadeOnDelete();")
            ->and(strpos($migration, "dropIfExists('sessions')"))->toBeLessThan(strpos($migration, "dropIfExists('users')"))
            ->and(($this->read)('database/migrations/2024_01_01_000000_create_passkeys_table.php'))->toContain('$table->id();', "\$table->foreignUuid('user_id')->constrained('users')")
            ->and(($this->read)('app/Models/User.php'))->toContain('use HasFactory, KeyedByUuid, ', '@property string $id', "#[Fillable(['id', 'name',")
            ->and(($this->read)('database/factories/UserFactory.php'))->toContain("'id' => fake()->uuid(),")
            ->and(($this->read)('app/Actions/Fortify/CreateNewUser.php'))->toContain('private readonly IdGenerator $ids', "'id' => \$this->ids->next(),")
            ->and(($this->read)('app/Concerns/ProfileValidationRules.php'))->toContain('?string $userId');
    });
});

describe('auditPage', function () {
    it('routes the audit log for every signed-in user and marks where new pages go', function () {
        $this->setup->auditPage();
        $routes = ($this->read)('routes/web.php');

        expect($routes)->toContain("    // kit:routes\n});", "Route::middleware('auth')->group(function () {\n    Route::resource('audit-entries', AuditEntryController::class)->only(['index']);")
            ->and(json_decode(($this->read)('lang/en.json'), true))->toHaveKeys(['common.save', 'audit-entries.title']);
    });

    it('adds only the missing keys to a locale the project already has', function () {
        File::ensureDirectoryExists($this->project.'/lang');
        file_put_contents($this->project.'/lang/th.json', json_encode(['common.save' => 'บันทึก']));

        $this->setup->auditPage();
        $thai = json_decode(($this->read)('lang/th.json'), true);

        expect($thai['common.save'])->toBe('บันทึก')
            ->and($thai)->toHaveKey('common.cancel')
            ->and($this->project.'/lang/en.json')->not->toBeFile();
    });
});

describe('packageManager', function () {
    it('reads the package manager from the lockfile', function (string $lockfile, string $manager) {
        touch($this->project.'/'.$lockfile);

        expect($this->setup->packageManager())->toBe($manager);
    })->with([['package-lock.json', 'npm'], ['pnpm-lock.yaml', 'pnpm'], ['bun.lock', 'bun']]);
});
