<?php

use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Setup\ProjectSetup;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;
use Symfony\Component\Yaml\Yaml;

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

    it('checks with the kit\'s Prettier and ESLint in place of vite-plus\'s formatter and linter', function () {
        $result = $this->setup->npmDependencies();
        $scripts = json_decode(($this->read)('package.json'), true)['scripts'];

        expect($scripts['check'])->toBe('prettier --check resources/ && eslint .')
            ->and($scripts['check:fix'])->toBe('eslint . --fix && prettier --write resources/')
            ->and($scripts['build'])->toBe('vp build')
            ->and($result['manual'])->toBe([]);
    });

    it('leaves a script of the project\'s own that runs vite-plus\'s linter to the user', function () {
        $package = json_decode(($this->read)('package.json'), true);
        $package['scripts']['check'] = 'vp lint && tsc --noEmit';
        file_put_contents($this->project.'/package.json', json_encode($package));

        $manual = $this->setup->npmDependencies()['manual'];

        expect($manual)->toBe(['package.json: the "check" script runs `vp lint`, which formats and lints a second time with rules of its own; run the kit\'s checks there instead (`prettier --check resources/ && eslint .`, stack.md)'])
            ->and(json_decode(($this->read)('package.json'), true)['scripts']['check'])->toBe('vp lint && tsc --noEmit');
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
            ->and($pest)->toContain('use Illuminate\Foundation\Testing\RefreshDatabase;', 'use App\Models\User;', 'function auditLogReader(): User', 'function auditLogOutsider(): ?User', "expect()->extend('toBeOne'", 'function something()')
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
            ->and(($this->read)('phpunit.xml'))->toContain('<env name="DB_CONNECTION" value="pgsql"/>', "<testsuite name=\"Browser\">\n            <directory>tests/Browser</directory>\n        </testsuite>", 'vendor/playerarm123/laravel-workflow-kit/tests/Architecture')
            ->and(($this->read)('phpstan.neon'))->toContain('tests/PHPStan/write-path.php')
            ->and(($this->read)('config/app.php'))->toContain("'currency' => env('APP_CURRENCY', 'USD')")
            ->and(($this->read)('vite.config.ts'))->toContain("            input: [\n                'resources/css/app.css',\n                'resources/js/app.tsx',\n                'resources/js/kit/structure.tsx',\n            ],\n");
    });

    it('keeps the inputs of laravel() on one line while they fit', function () {
        file_put_contents($this->project.'/vite.config.ts', str_replace("input: ['resources/css/app.css', 'resources/js/app.tsx']", "input: ['resources/js/app.tsx']", ($this->read)('vite.config.ts')));

        $this->setup->config();

        expect(($this->read)('vite.config.ts'))->toContain("            input: ['resources/js/app.tsx', 'resources/js/kit/structure.tsx'],\n");
    });

    it('sets mysql with its own port and user', function () {
        (new ProjectSetup(WorkflowKit::kitPath(), $this->project, 'mysql'))->config();

        expect(($this->read)('.env.example'))->toContain("DB_CONNECTION=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306", 'DB_USERNAME=root');
    });

    it('moves a .env still on sqlite to the chosen engine', function () {
        copy($this->project.'/.env.example', $this->project.'/.env');

        $this->setup->config();

        expect(($this->read)('.env'))->toContain("DB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_PORT=5432\nDB_DATABASE=laravel\nDB_USERNAME=postgres\nDB_PASSWORD=");
    });

    it('keeps the database and credentials of a .env already on the chosen engine', function () {
        file_put_contents($this->project.'/.env', "APP_NAME=Clinic\n\nDB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=6543\nDB_DATABASE=alone_clinic\nDB_USERNAME=playerarm123\nDB_PASSWORD=secret\n");

        $this->setup->config();

        expect(($this->read)('.env'))->toContain("DB_CONNECTION=pgsql\nDB_HOST=db.internal\nDB_PORT=6543\nDB_DATABASE=alone_clinic\nDB_USERNAME=playerarm123\nDB_PASSWORD=secret\n", 'NIGHTWATCH_TOKEN=')
            ->and(($this->read)('.env.example'))->toContain('DB_DATABASE=laravel', 'DB_USERNAME=postgres');
    });

    it('fills only the keys a .env on the chosen engine leaves out', function () {
        file_put_contents($this->project.'/.env', "DB_CONNECTION=pgsql\nDB_DATABASE=alone_clinic\n# DB_USERNAME=root\n");

        $this->setup->config();

        expect(($this->read)('.env'))->toContain('DB_DATABASE=alone_clinic', "DB_USERNAME=postgres\n", 'DB_HOST=127.0.0.1', 'DB_PORT=5432')
            ->not->toContain('# DB_USERNAME');
    });
});

describe('the CI workflow', function () {
    it('runs the starter kit\'s workflow on the chosen engine, with the test database and Chromium', function () {
        $this->setup->config();
        $workflow = ($this->read)('.github/workflows/tests.yml');

        expect($workflow)->toContain(
            "    runs-on: ubuntu-latest\n\n    services:\n      postgres:\n        image: postgres:17\n        env:\n          POSTGRES_USER: postgres\n          POSTGRES_DB: laravel\n          POSTGRES_HOST_AUTH_METHOD: trust\n        ports:\n          - 5432:5432\n",
            "      - name: Create the testing database phpunit.xml names\n        run: psql -h 127.0.0.1 -U postgres -c 'create database testing'\n\n      - name: Setup Application\n        run: composer setup\n\n      - name: Install Chromium for the Browser tests\n        run: npx playwright install --with-deps chromium\n\n      - name: Run CI Checks\n",
            "php-version: '8.4'",
        )->and(Yaml::parse($workflow)['jobs']['ci']['services']['postgres']['ports'])->toBe(['5432:5432']);
    });

    it('declares the mysql and mariadb services with the root user and no password', function (string $database, string $service, string $image) {
        (new ProjectSetup(WorkflowKit::kitPath(), $this->project, $database))->config();
        $workflow = Yaml::parse(($this->read)('.github/workflows/tests.yml'));

        expect($workflow['jobs']['ci']['services'][$service]['image'])->toBe($image)
            ->and($workflow['jobs']['ci']['services'][$service]['ports'])->toBe(['3306:3306'])
            ->and(array_column($workflow['jobs']['ci']['steps'], 'run'))->toContain("mysql -h 127.0.0.1 -u root -e 'create database testing'");
    })->with([['mysql', 'mysql', 'mysql:8.4'], ['mariadb', 'mariadb', 'mariadb:11.4']]);

    it('leaves a workflow of another shape to the user', function () {
        file_put_contents($this->project.'/.github/workflows/tests.yml', "name: tests\n\non: push\n\njobs:\n  test:\n    runs-on: ubuntu-latest\n    steps:\n      - run: php artisan test\n");

        $manual = $this->setup->config()['manual'];

        expect($manual)->toHaveCount(1)
            ->and($manual[0])->toStartWith('.github/workflows/tests.yml: run the job with a postgres:17 service')
            ->and(($this->read)('.github/workflows/tests.yml'))->not->toContain('services:');
    });

    it('leaves a workflow whose services run another engine to the user', function () {
        (new ProjectSetup(WorkflowKit::kitPath(), $this->project, 'mysql'))->config();

        expect($this->setup->config()['manual'])->toHaveCount(1)
            ->and(($this->read)('.github/workflows/tests.yml'))->not->toContain('image: postgres');
    });

    it('asks nothing of a project without a workflow', function () {
        File::deleteDirectory($this->project.'/.github');

        expect($this->setup->config()['manual'])->toBe([]);
    });
});

describe('wiring', function () {
    it('wires the providers, the exception responses, the actor and the shared props', function () {
        $this->setup->wiring();

        expect(($this->read)('bootstrap/providers.php'))->toContain('use App\Infra\Persistence\PersistenceServiceProvider;', 'use App\Infra\Time\TimeServiceProvider;', '    KitServiceProvider::class,', '    TimeServiceProvider::class,')
            ->and(($this->read)('bootstrap/app.php'))->toContain('ExceptionResponses::register($exceptions);', "            HandleInertiaRequests::class,\n            InitializeUserContext::class,")
            ->and(($this->read)('app/Providers/AppServiceProvider.php'))->toContain('Inertia::handleExceptionsUsing(ExceptionResponses::respond(...));')
            ->and(($this->read)('app/Http/Middleware/HandleInertiaRequests.php'))->toContain("'translations' => \$this->translations(),", 'private function messagesFor(string $locale): array')
            ->and(($this->read)('resources/js/types/global.d.ts'))->toContain('sharedPageProps: SharedProps;')
            ->and(($this->read)('resources/js/types/index.ts'))->toContain("export type * from './shared';")
            ->and(($this->read)('resources/js/app.tsx'))->toContain("<FlashToast />\n                <Toaster />")
            ->and(($this->read)('resources/js/components/ui/sonner.tsx'))->not->toContain('useFlashToast');
    });

    it('shares the main menu, and points the sidebar and the header at it', function () {
        $this->setup->files();
        $this->setup->wiring();

        expect($this->project.'/app/Http/Navigation.php')->toBeFile()
            ->and(($this->read)('app/Http/Middleware/HandleInertiaRequests.php'))->toContain("'menu' => fn (): array => Navigation::for(\$request->user()),", "use App\\Http\\Navigation;\nuse Illuminate\\Http\\Request;")
            ->and(($this->read)('resources/js/types/shared.ts'))->toContain('menu: MenuEntry[];');

        foreach (['app-sidebar', 'app-header'] as $component) {
            $code = ($this->read)("resources/js/components/{$component}.tsx");

            expect($code)->toContain("import { useMainNavItems } from '@/hooks/use-main-nav-items';", '    const mainNavItems = useMainNavItems();')
                ->not->toContain('const mainNavItems: NavItem[]')
                ->not->toContain('LayoutGrid')
                ->not->toContain('mainNavItems.map(');
        }

        expect(($this->read)('resources/js/components/app-sidebar.tsx'))->toContain("import { MainNavSidebar } from '@/components/main-nav-sidebar';", '<MainNavSidebar items={mainNavItems} />')
            ->not->toContain('NavMain')
            ->and(($this->read)('resources/js/components/app-header.tsx'))->toContain("import { MainNavHeader, MainNavMobile } from '@/components/main-nav-header';", '<MainNavHeader items={mainNavItems} />', "<MainNavMobile\n                                                items={mainNavItems}\n                                            />")
            ->not->toContain('activeItemStyles')
            ->not->toContain('useCurrentUrl')
            ->not->toContain('navigationMenuTriggerStyle')
            ->not->toContain('import { cn, toUrl }');
    });

    it('brings a project set up before menu groups onto them, as a fresh one gets them', function () {
        $this->setup->files();
        $this->setup->wiring();
        $fresh = array_map($this->read, $files = [
            'app/Http/Navigation.php',
            'resources/js/types/shared.ts',
            'resources/js/components/app-sidebar.tsx',
            'resources/js/components/app-header.tsx',
        ]);

        foreach ($files as $file) {
            copy(dirname(__DIR__, 4).'/Fixtures/BeforeMenuGroups/'.$file, $this->project.'/'.$file);
        }

        expect($this->setup->wiring()['manual'])->toBe([])
            ->and(array_map($this->read, $files))->toBe($fresh);
    });

    it('adds the menu to a project set up before the menu existed', function () {
        $this->setup->files();
        $this->setup->wiring();
        $middleware = ($this->read)('app/Http/Middleware/HandleInertiaRequests.php');
        file_put_contents($this->project.'/app/Http/Middleware/HandleInertiaRequests.php', str_replace("            'menu' => fn (): array => Navigation::for(\$request->user()),\n", '', $middleware));
        $shared = ($this->read)('resources/js/types/shared.ts');
        file_put_contents($this->project.'/resources/js/types/shared.ts', (string) preg_replace("/\n    \/\*\* @see app\/Http\/Navigation.php[^\n]*\n    menu: MenuEntry\[\];|import type \{ MenuEntry \} from '@\/types\/ui';\n/", '', $shared));

        $this->setup->wiring();

        expect(($this->read)('app/Http/Middleware/HandleInertiaRequests.php'))->toBe($middleware)
            ->and(($this->read)('resources/js/types/shared.ts'))->toBe($shared);
    });

    it('leaves a sidebar it cannot read the way it expects to the user', function () {
        file_put_contents($this->project.'/resources/js/components/app-sidebar.tsx', "export function AppSidebar() {\n    return null;\n}\n");

        expect($this->setup->wiring()['manual'])->toContain('resources/js/components/app-sidebar.tsx: take the main menu from useMainNavItems() (@/hooks/use-main-nav-items) instead of a list of its own, and draw it with <MainNavSidebar items={mainNavItems} /> (list-pages.md, "Menu")');
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

describe('users without registration', function () {
    it('asks nothing of a CreateNewUser a project made without registration does not have', function () {
        unlink($this->project.'/app/Actions/Fortify/CreateNewUser.php');
        File::ensureDirectoryExists($this->project.'/config');
        file_put_contents($this->project.'/config/fortify.php', "<?php\n\nreturn [\n    'features' => [\n        Features::resetPasswords(),\n        // Features::registration(),\n    ],\n];\n");

        expect($this->setup->users()['manual'])->toBe([]);
    });

    it('still asks for the id when Fortify registers users and CreateNewUser is gone', function () {
        unlink($this->project.'/app/Actions/Fortify/CreateNewUser.php');
        File::ensureDirectoryExists($this->project.'/config');
        file_put_contents($this->project.'/config/fortify.php', "<?php\n\nreturn [\n    'features' => [\n        Features::registration(),\n    ],\n];\n");

        expect($this->setup->users()['manual'])->toBe(['app/Actions/Fortify/CreateNewUser.php is missing: give the new user its id from IdGenerator in CreateNewUser']);
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
        file_put_contents($this->project.'/.env.example', str_replace(['APP_LOCALE=en', 'APP_FALLBACK_LOCALE=en'], ['APP_LOCALE=th', 'APP_FALLBACK_LOCALE=th'], ($this->read)('.env.example')));
        File::ensureDirectoryExists($this->project.'/lang');
        file_put_contents($this->project.'/lang/th.json', json_encode(['common.save' => 'บันทึก']));

        $manual = $this->setup->auditPage()['manual'];
        $thai = json_decode(($this->read)('lang/th.json'), true);

        expect($thai['common.save'])->toBe('บันทึก')
            ->and($thai)->toHaveKey('common.cancel')
            ->and($this->project.'/lang/en.json')->not->toBeFile()
            ->and($manual)->toHaveCount(1)
            ->and($manual[0])->toStartWith('lang/th.json: translate the ')->toEndWith(' keys the kit added in English');
    });

    it('writes the file of the locale and the fallback locale the app runs in, and asks for the translation', function () {
        file_put_contents($this->project.'/.env', "APP_LOCALE=th\nAPP_FALLBACK_LOCALE=en\n");

        $manual = $this->setup->auditPage()['manual'];

        expect(json_decode(($this->read)('lang/th.json'), true))->toHaveKeys(['common.save', 'audit-entries.title'])
            ->and(json_decode(($this->read)('lang/th.json'), true)['common.save'])->toBe(json_decode(($this->read)('lang/en.json'), true)['common.save'])
            ->and($manual)->toHaveCount(1)
            ->and($manual[0])->toStartWith('lang/th.json: translate the ');
    });

    it('reads the locale from .env.example when the project has no .env', function () {
        file_put_contents($this->project.'/.env.example', str_replace('APP_LOCALE=en', 'APP_LOCALE=th', ($this->read)('.env.example')));

        $this->setup->auditPage();

        expect($this->project.'/lang/th.json')->toBeFile()
            ->and($this->project.'/lang/en.json')->toBeFile();
    });

    it('writes the file of the locale .env.example names too, which every clone runs in', function () {
        file_put_contents($this->project.'/.env', "APP_LOCALE=th\nAPP_FALLBACK_LOCALE=th\n");

        $this->setup->auditPage();

        expect($this->project.'/lang/th.json')->toBeFile()
            ->and($this->project.'/lang/en.json')->toBeFile();
    });

    it('asks for no translation once every locale file holds the kit\'s keys', function () {
        file_put_contents($this->project.'/.env', "APP_LOCALE=th\nAPP_FALLBACK_LOCALE=th\n");
        $this->setup->auditPage();

        expect($this->setup->auditPage()['manual'])->toBe([]);
    });
});

describe('boostLoadsTheKit', function () {
    it('reads the kit from the packages of boost.json', function (?string $boost, bool $loads) {
        if ($boost !== null) {
            file_put_contents($this->project.'/boost.json', $boost);
        }

        expect($this->setup->boostLoadsTheKit())->toBe($loads);
    })->with([
        'no boost.json' => [null, false],
        'no packages' => ['{"agents": ["claude_code"]}', false],
        'the kit' => ['{"packages": ["playerarm123/laravel-workflow-kit"]}', true],
    ]);
});

describe('builtWithTheKitsPages', function () {
    it('reads the kit\'s pages from the Vite manifest', function (?array $manifest, bool $built) {
        if ($manifest !== null) {
            File::ensureDirectoryExists($this->project.'/public/build');
            file_put_contents($this->project.'/public/build/manifest.json', json_encode($manifest));
        }

        expect($this->setup->builtWithTheKitsPages())->toBe($built);
    })->with([
        'never built' => [null, false],
        'built before the kit' => [['resources/js/app.tsx' => ['file' => 'assets/app.js']], false],
        'built with the kit' => [[
            'resources/js/app.tsx' => ['file' => 'assets/app.js'],
            'resources/js/kit/structure.tsx' => ['file' => 'assets/structure.js'],
            'resources/js/pages/audit-entries/index.tsx' => ['file' => 'assets/audit-entries.js'],
        ], true],
    ]);
});

describe('packageManager', function () {
    it('reads the package manager from the lockfile', function (string $lockfile, string $manager) {
        touch($this->project.'/'.$lockfile);

        expect($this->setup->packageManager())->toBe($manager);
    })->with([['package-lock.json', 'npm'], ['pnpm-lock.yaml', 'pnpm'], ['bun.lock', 'bun']]);
});

describe('teamsMarkerOf', function () {
    it('finds nothing on the starter kit without teams', function () {
        expect(ProjectSetup::teamsMarkerOf($this->project))->toBeNull();
    });

    it('names the file the teams variant ships', function (string $marker) {
        File::ensureDirectoryExists(dirname($this->project.'/'.$marker));
        file_put_contents($this->project.'/'.$marker, "<?php\n");

        expect(ProjectSetup::teamsMarkerOf($this->project))->toBe($marker);
    })->with(['app/Concerns/HasTeams.php', 'database/migrations/2026_01_27_000001_create_teams_table.php']);

    it('leaves a Team model of the project\'s own alone', function () {
        file_put_contents($this->project.'/app/Models/Team.php', "<?php\n");

        expect(ProjectSetup::teamsMarkerOf($this->project))->toBeNull();
    });
});

describe('pnpm-workspace.yaml', function () {
    it('removes the starter kit\'s own pnpm-workspace.yaml from a project that locks with npm', function () {
        touch($this->project.'/package-lock.json');
        file_put_contents($this->project.'/pnpm-workspace.yaml', "packages:\n    - '.'\n\npublicHoistPattern:\n    - '@inertiajs/core'\n");

        $result = $this->setup->npmDependencies();

        expect($result['manual'])->toBe([])
            ->and($result['changed'])->toContain('pnpm-workspace.yaml (removed)')
            ->and($this->project.'/pnpm-workspace.yaml')->not->toBeFile();
    });

    it('leaves a workspace that names other packages to the user', function () {
        touch($this->project.'/package-lock.json');
        file_put_contents($this->project.'/pnpm-workspace.yaml', "packages:\n    - '.'\n    - 'packages/*'\n");

        expect($this->setup->npmDependencies()['manual'])->toHaveCount(1)
            ->and($this->project.'/pnpm-workspace.yaml')->toBeFile();
    });

    it('leaves a pnpm-workspace.yaml beside another lockfile to the user, since tools read it as pnpm', function () {
        touch($this->project.'/package-lock.json');
        file_put_contents($this->project.'/pnpm-workspace.yaml', "packages: []\n");

        $manual = $this->setup->npmDependencies()['manual'];

        expect($manual)->toHaveCount(1)
            ->and($manual[0])->toContain('pnpm-workspace.yaml', 'locks with npm', '"packageManager"')
            ->and(file_exists($this->project.'/pnpm-workspace.yaml'))->toBeTrue();
    });

    it('says nothing when the project locks with pnpm or names its package manager', function (string $setup) {
        file_put_contents($this->project.'/pnpm-workspace.yaml', "packages: []\n");

        if ($setup === 'pnpm-lock.yaml') {
            touch($this->project.'/pnpm-lock.yaml');
        } else {
            $package = json_decode(($this->read)('package.json'), true);
            file_put_contents($this->project.'/package.json', json_encode([...$package, 'packageManager' => 'npm@10.9.4']));
        }

        expect($this->setup->npmDependencies()['manual'])->toBe([]);
    })->with(['pnpm-lock.yaml', 'packageManager']);
});
