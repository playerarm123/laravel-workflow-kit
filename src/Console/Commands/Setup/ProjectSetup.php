<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Setup;

use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\KitInstaller;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Brings a project made from Laravel's React starter kit to the shape the kit's checks hold it to.
 *
 * Each step edits a file only when it is not in that shape yet, so a second run changes nothing,
 * and a step that cannot find the code it edits leaves the file alone and says what to do by
 * hand. Every step returns what it changed and what is left to a person.
 *
 * The dependency steps only edit composer.json and package.json; `KitSetupCommand` runs the
 * installs, so a test can run every step without touching the network.
 */
final class ProjectSetup
{
    public const array DATABASES = ['pgsql', 'mysql', 'mariadb'];

    /** The Composer packages the stack requires that the starter kit does not bring (stack.md). */
    private const array COMPOSER_REQUIRE = [
        'laravel/nightwatch' => '^1.0',
        'league/flysystem-aws-s3-v3' => '^3.0',
        'spatie/laravel-data' => '^4.23',
    ];

    private const array COMPOSER_REQUIRE_DEV = [
        'laravel/boost' => '^2.2',
        'larastan/larastan' => '^3.9',
        'pestphp/pest' => '^5.1',
        'pestphp/pest-plugin-browser' => '^5.0',
        'pestphp/pest-plugin-laravel' => '^5.0',
    ];

    /** The starter kit's test runner. Pest brings its own PHPUnit, on the major Pest needs. */
    private const array COMPOSER_REMOVE = ['phpunit/phpunit'];

    private const array NPM_DEPENDENCIES = [
        '@dagrejs/dagre' => '^3.1.1',
        '@tanstack/react-table' => '^9.2.4',
        '@xyflow/react' => '^12.12.0',
        'date-fns' => '^4.4.0',
        'lucide-react' => '^1.45.0',
        'radix-ui' => '^1.6.7',
        'react-day-picker' => '^10.0.1',
        'shadcn' => '^4.21.0',
    ];

    private const array NPM_DEV_DEPENDENCIES = [
        '@eslint/js' => '^9.19.0',
        '@stylistic/eslint-plugin' => '^5.10.0',
        'eslint' => '^9.17.0',
        'eslint-config-prettier' => '^10.0.1',
        'eslint-import-resolver-typescript' => '^4.4.4',
        'eslint-plugin-import' => '^2.32.0',
        'eslint-plugin-react' => '^7.37.3',
        'eslint-plugin-react-hooks' => '^7.0.0',
        'globals' => '^15.14.0',
        'playwright' => '^1.63.0',
        'prettier' => '^3.4.2',
        'prettier-plugin-tailwindcss' => '^0.6.11',
        'typescript-eslint' => '^8.23.0',
    ];

    private const array NPM_SCRIPTS = [
        'format' => 'prettier --write resources/',
        'format:check' => 'prettier --check resources/',
        'lint' => 'eslint . --fix',
        'lint:check' => 'eslint .',
    ];

    private const array PROVIDERS = [
        'App\Providers\KitServiceProvider',
        'App\Infra\Audit\AuditServiceProvider',
        'App\Infra\Persistence\PersistenceServiceProvider',
    ];

    private const string ARCHITECTURE_SUITE = 'vendor/playerarm123/laravel-workflow-kit/tests/Architecture';

    private const string PHPSTAN_INCLUDE = 'vendor/playerarm123/laravel-workflow-kit/tests/PHPStan/write-path.php';

    /** @var array{changed: list<string>, manual: list<string>} */
    private array $result = ['changed' => [], 'manual' => []];

    public function __construct(
        private readonly string $kitPath,
        private readonly string $projectPath,
        private readonly string $database,
    ) {}

    /**
     * composer.json declares what the stack requires and drops PHPUnit, which Pest replaces.
     *
     * @return array{changed: list<string>, manual: list<string>, packages: list<string>}
     */
    public function composerDependencies(): array
    {
        $this->start();
        $composer = $this->readJson('composer.json');
        $before = $composer;
        $touched = [];

        if (! str_starts_with((string) ($composer['require']['php'] ?? ''), '^8.4')) {
            $composer['require']['php'] = '^8.4';
        }

        foreach ([['require', self::COMPOSER_REQUIRE], ['require-dev', self::COMPOSER_REQUIRE_DEV]] as [$section, $packages]) {
            foreach ($packages as $package => $constraint) {
                if (! isset($composer['require'][$package]) && ! isset($composer['require-dev'][$package])) {
                    $composer[$section][$package] = $constraint;
                    $touched[] = $package;
                }
            }

            uksort($composer[$section], fn (string $a, string $b): int => [! self::isPlatform($a), $a] <=> [! self::isPlatform($b), $b]);
        }

        foreach (self::COMPOSER_REMOVE as $package) {
            if (isset($composer['require-dev'][$package])) {
                unset($composer['require-dev'][$package]);
                $touched[] = $package;
            }
        }

        if ($composer !== $before) {
            $this->writeJson('composer.json', $composer);
        }

        return [...$this->finish(), 'packages' => $touched];
    }

    /**
     * package.json declares the lint, format and UI packages the stack requires, swaps the
     * `@radix-ui/*` packages for `radix-ui`, and every import of them follows.
     *
     * @return array{changed: list<string>, manual: list<string>, packages: list<string>}
     */
    public function npmDependencies(): array
    {
        $this->start();
        $package = $this->readJson('package.json');
        $before = $package;
        $touched = [];

        foreach (['dependencies', 'devDependencies'] as $section) {
            foreach (array_keys($package[$section] ?? []) as $name) {
                $name = (string) $name;

                if (str_starts_with($name, '@radix-ui/')) {
                    unset($package[$section][$name]);
                    $touched[] = $name;
                }
            }
        }

        foreach ([['dependencies', self::NPM_DEPENDENCIES], ['devDependencies', self::NPM_DEV_DEPENDENCIES]] as [$section, $packages]) {
            foreach ($packages as $name => $constraint) {
                $current = $package['dependencies'][$name] ?? $package['devDependencies'][$name] ?? null;

                if ($current === null || $this->major($current) !== $this->major($constraint)) {
                    unset($package['dependencies'][$name], $package['devDependencies'][$name]);
                    $package[$section][$name] = $constraint;
                    $touched[] = $name;
                }
            }

            ksort($package[$section]);
        }

        foreach (self::NPM_SCRIPTS as $script => $command) {
            $package['scripts'][$script] ??= $command;
        }

        if ($package !== $before) {
            $this->writeJson('package.json', $package);
        }

        foreach ($this->filesUnder('resources/js', ['ts', 'tsx']) as $file) {
            $this->edit($file, fn (string $code): string => RadixImports::rewrite($code));
        }

        return [...$this->finish(), 'packages' => $touched];
    }

    /**
     * The kit's own files, every one replaced by the kit's copy, and the files the kit writes once
     * for a new project: the adapters and middleware the wiring names, the audit log's policy, the
     * test helpers, the lint and format config and the UI components the kit's pages use.
     *
     * @return array{changed: list<string>, manual: list<string>}
     */
    public function files(): array
    {
        $this->start();
        $report = (new KitInstaller($this->kitPath, $this->projectPath))->install(force: true);

        foreach ([...$report['written'], ...$report['overwritten']] as $path) {
            $this->result['changed'][] = $path;
        }

        foreach ($this->kitFilesIn('setup') as $relative => $source) {
            if (! is_file($this->path($relative))) {
                $this->put($relative, (string) file_get_contents($source));
            }
        }

        /*
         * A starter kit made with Pest already has its own tests/Pest.php, which the loop above
         * keeps. It still needs the kit's binding and helpers.
         */
        $this->edit('tests/Pest.php', fn (string $code): string => PestFile::complete($code, (string) file_get_contents($this->kitPath.'/setup/tests/Pest.php')),
            need: ['function auditLogReader(', 'function auditLogOutsider('],
            hint: "bind TestCase with RefreshDatabase to 'Feature' and 'Browser', and declare auditLogReader(): User and auditLogOutsider(): User (testing.md, audit-log.md)");

        if (is_file($this->path('tests/Pest.php')) && ! PestFile::bindsAsTheKitWants((string) file_get_contents($this->path('tests/Pest.php')))) {
            $this->result['manual'][] = "tests/Pest.php: bind TestCase with RefreshDatabase to 'Feature' and 'Browser' as ".str_replace("\n", ' ', PestFile::BINDING).' (testing.md)';
        }

        if (! is_file($this->path('rule-overrides.json'))) {
            $this->put('rule-overrides.json', "{\n    \"overrides\": []\n}\n");
        }

        foreach (['tests/Unit/ExampleTest.php', 'tests/Feature/ExampleTest.php'] as $example) {
            if (is_file($this->path($example))) {
                unlink($this->path($example));
                $this->result['changed'][] = $example.' (removed)';
            }
        }

        return $this->finish();
    }

    /**
     * The engine every environment and the tests run on, the env keys the kit asks for, the
     * Architecture suite, the PHPStan rule and the one currency every amount is in.
     *
     * @return array{changed: list<string>, manual: list<string>}
     */
    public function config(): array
    {
        $this->start();
        [$port, $username] = $this->database === 'pgsql' ? ['5432', 'postgres'] : ['3306', 'root'];
        $env = [
            'DB_CONNECTION' => $this->database,
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => $port,
            'DB_DATABASE' => 'laravel',
            'DB_USERNAME' => $username,
            'DB_PASSWORD' => '',
        ];

        foreach (['.env.example', '.env'] as $file) {
            if (! is_file($this->path($file))) {
                continue;
            }

            $this->edit($file, function (string $code) use ($env): string {
                foreach ($env as $key => $value) {
                    $code = EnvFile::set($code, $key, $value);
                }

                return EnvFile::set($code, 'NIGHTWATCH_TOKEN', '', onlyWhenMissing: true);
            });
        }

        $this->edit('phpunit.xml', function (string $code): string {
            $code = (string) preg_replace('#<env name="DB_CONNECTION" value="[^"]*"\s*/>#', sprintf('<env name="DB_CONNECTION" value="%s"/>', $this->database), $code);
            $code = (string) preg_replace('#<env name="DB_DATABASE" value=":memory:"\s*/>#', '<env name="DB_DATABASE" value="testing"/>', $code);

            if (! str_contains($code, self::ARCHITECTURE_SUITE)) {
                $code = (string) preg_replace(
                    '#(\n(\s*)</testsuites>)#',
                    "\n$2    <testsuite name=\"Architecture\">\n$2        <directory>".self::ARCHITECTURE_SUITE."</directory>\n$2    </testsuite>$1",
                    $code,
                    1,
                );
            }

            return $code;
        });

        if (! is_file($this->path('phpstan.neon'))) {
            $this->put('phpstan.neon', "includes:\n    - vendor/larastan/larastan/extension.neon\n    - ".self::PHPSTAN_INCLUDE."\n\nparameters:\n    paths:\n        - app/\n        - bootstrap/app.php\n        - config/\n        - database/\n        - routes/\n\n    level: 7\n");
        } else {
            $this->edit('phpstan.neon', fn (string $code): string => str_contains($code, self::PHPSTAN_INCLUDE)
                ? $code
                : (string) preg_replace('/^includes:\n/m', "includes:\n    # Only App\\\\Infra writes to the database — write-path.md\n    - ".self::PHPSTAN_INCLUDE."\n", $code, 1));
        }

        $this->edit('vite.config.ts', fn (string $code): string => str_contains($code, "'resources/js/kit/structure.tsx'")
            ? $code
            : (string) preg_replace("/(input: \\[[^\\]]*'resources\\/js\\/app\\.tsx')/", "$1, 'resources/js/kit/structure.tsx'", $code, 1),
            need: ["'resources/js/kit/structure.tsx'"], hint: 'add resources/js/kit/structure.tsx to the inputs of laravel() in vite.config.ts');

        $this->edit('config/app.php', fn (string $code): string => str_contains($code, "'currency' =>")
            ? $code
            : (string) preg_replace(
                "/(\n    'timezone' => [^\n]+\n)/",
                "$1\n    /*\n    |--------------------------------------------------------------------------\n    | Currency\n    |--------------------------------------------------------------------------\n    |\n    | Every amount the application stores is in this one currency (numbers.md).\n    |\n    */\n\n    'currency' => env('APP_CURRENCY', 'USD'),\n",
                $code,
                1,
            ));

        return $this->finish();
    }

    /**
     * The providers, the exception responses, the actor middleware, the props every page shares
     * and the flash toast, wired the way the guidelines' kit files describe.
     *
     * @return array{changed: list<string>, manual: list<string>}
     */
    public function wiring(): array
    {
        $this->start();

        $this->edit('bootstrap/providers.php', function (string $code): string {
            foreach (self::PROVIDERS as $provider) {
                $short = substr($provider, (int) strrpos($provider, '\\') + 1);

                if (! str_contains($code, $short.'::class')) {
                    $code = PhpFile::addUse($code, $provider);
                    $code = (string) preg_replace('/\n\];\s*$/', "\n    {$short}::class,\n];\n", $code, 1);
                }
            }

            return $code;
        });

        $this->edit('bootstrap/app.php', function (string $code): string {
            $code = PhpFile::addUse($code, 'App\Http\ExceptionResponses');
            $code = PhpFile::addUse($code, 'App\Http\Middleware\InitializeUserContext');

            if (! str_contains($code, 'ExceptionResponses::register(')) {
                $code = (string) preg_replace('/(->withExceptions\(function \(Exceptions \$exceptions\): void \{\n)/', "$1        ExceptionResponses::register(\$exceptions);\n\n", $code, 1);
            }

            if (! str_contains($code, 'InitializeUserContext::class,')) {
                $code = (string) preg_replace('/(\n(\s*)HandleInertiaRequests::class,\n)/', "$1$2InitializeUserContext::class,\n", $code, 1);
            }

            return $code;
        }, need: ['ExceptionResponses::register(', 'InitializeUserContext::class,'], hint: 'call ExceptionResponses::register($exceptions) in withExceptions() and append InitializeUserContext to the web middleware');

        $this->edit('app/Providers/AppServiceProvider.php', function (string $code): string {
            if (str_contains($code, 'Inertia::handleExceptionsUsing(')) {
                return $code;
            }

            $code = PhpFile::addUse($code, 'App\Http\ExceptionResponses');
            $code = PhpFile::addUse($code, 'Inertia\Inertia');

            return (string) preg_replace('/(public function boot\(\): void\n    \{\n)/', "$1        Inertia::handleExceptionsUsing(ExceptionResponses::respond(...));\n\n", $code, 1);
        }, need: ['Inertia::handleExceptionsUsing('], hint: 'call Inertia::handleExceptionsUsing(ExceptionResponses::respond(...)) in a provider\'s boot()');

        $this->edit('app/Http/Middleware/HandleInertiaRequests.php', fn (string $code): string => SharedProps::share($code),
            need: ["'translations' =>"], hint: "return 'locale', 'timezone', 'currency' and 'translations' from share()");

        $this->edit('resources/js/types/global.d.ts', fn (string $code): string => SharedProps::declare($code),
            need: ['SharedProps'], hint: 'declare sharedPageProps as SharedProps from @/types/shared');

        $this->edit('resources/js/types/index.ts', function (string $code): string {
            foreach (['audit-entry', 'data-table', 'pagination', 'shared'] as $module) {
                if (! str_contains($code, "'./{$module}'")) {
                    $code = rtrim($code)."\nexport type * from './{$module}';\n";
                }
            }

            return $code;
        });

        $this->edit('resources/js/components/ui/sonner.tsx', fn (string $code): string => (string) preg_replace(
            ["/import \\{ useFlashToast \\} from '@\\/hooks\\/use-flash-toast';\n/", "/\n    useFlashToast\\(\\);\n/"],
            ['', ''],
            $code,
        ));

        $this->edit('resources/js/app.tsx', function (string $code): string {
            if (str_contains($code, '<FlashToast')) {
                return $code;
            }

            $code = (string) preg_replace("/(import \\{ Toaster \\} from '@\\/components\\/ui\\/sonner';\n)/", "import { FlashToast } from '@/components/flash-toast';\n$1", $code, 1);

            return (string) preg_replace('/\n(\s*)<Toaster/', "\n$1<FlashToast />\n$1<Toaster", $code, 1);
        }, need: ['<FlashToast'], hint: 'render <FlashToast /> beside <Toaster /> in app.tsx');

        return $this->finish();
    }

    /**
     * The users table keyed on a uuid, as every table is (migrations.md, models.md). Safe only
     * before the first deploy: the migration is edited in place.
     *
     * @return array{changed: list<string>, manual: list<string>}
     */
    public function users(): array
    {
        $this->start();

        foreach ($this->migrationsCreating(['users']) as $migration) {
            $this->edit($migration, fn (string $code): string => self::dropSessionsFirst((string) preg_replace(
                ['/\$table->id\(\);/', '/\$table->foreignId\(\'user_id\'\)->nullable\(\)->index\(\);/'],
                ["\$table->uuid('id')->primary();", "\$table->foreignUuid('user_id')->nullable()->index()->constrained('users')->cascadeOnDelete();"],
                $code,
            )));
        }

        foreach ($this->migrationsCreating(['passkeys']) as $migration) {
            $this->edit($migration, fn (string $code): string => (string) preg_replace(
                '/\$table->foreignId\(\'user_id\'\)->constrained\(\)->cascadeOnDelete\(\);/',
                "\$table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();",
                $code,
            ));
        }

        $this->edit('app/Actions/Fortify/CreateNewUser.php', function (string $code): string {
            if (str_contains($code, 'IdGenerator')) {
                return $code;
            }

            $code = PhpFile::addUse($code, 'App\Domain\Shared\Ports\IdGenerator');
            $code = (string) preg_replace('/(\n    use [^;]+;\n)/', "$1\n    public function __construct(private readonly IdGenerator \$ids) {}\n", $code, 1);

            return (string) preg_replace('/(return User::create\\(\\[\\n)(\\s*)/', "$1$2'id' => \$this->ids->next(),\n$2", $code, 1);
        }, need: ['$this->ids->next()'], hint: 'give the new user its id from IdGenerator in CreateNewUser');

        $this->edit('app/Models/User.php', function (string $code): string {
            if (str_contains($code, 'KeyedByUuid')) {
                return $code;
            }

            $code = PhpFile::addUse($code, 'App\Models\Concerns\KeyedByUuid');
            $code = str_replace(' * @property int $id', ' * @property string $id', $code);
            $code = str_replace("#[Fillable(['name',", "#[Fillable(['id', 'name',", $code);

            return (string) preg_replace('/(\n    use HasFactory, )/', '$1KeyedByUuid, ', $code, 1);
        }, need: ['KeyedByUuid, '], hint: 'use App\Models\Concerns\KeyedByUuid in App\Models\User');

        $this->edit('app/Concerns/ProfileValidationRules.php', fn (string $code): string => str_replace('?int $userId', '?string $userId', $code));

        $this->edit('database/factories/UserFactory.php', fn (string $code): string => str_contains($code, "'id' => fake()->uuid()")
            ? $code
            : (string) preg_replace('/(public function definition\(\): array\n    \{\n        return \[\n)/', "$1            'id' => fake()->uuid(),\n", $code, 1),
            need: ["'id' => fake()->uuid()"], hint: "fill 'id' => fake()->uuid() in UserFactory::definition()");

        return $this->finish();
    }

    /**
     * The audit log page's route and every word the kit's pages speak, in every locale.
     *
     * @return array{changed: list<string>, manual: list<string>}
     */
    public function auditPage(): array
    {
        $this->start();

        $this->edit('routes/web.php', function (string $code): string {
            if (! str_contains($code, '// kit:routes')) {
                $code = (string) preg_replace(
                    "/(Route::middleware\\(\\['auth', 'verified'\\]\\)->group\\(function \\(\\) \\{\n(?:.*\n)*?)(\\}\\);)/",
                    "$1    // kit:routes\n$2",
                    $code,
                    1,
                );
            }

            if (str_contains($code, "'audit-entries'")) {
                return $code;
            }

            $code = PhpFile::addUse($code, 'App\Http\Controllers\AuditEntryController');

            /*
             * Signed in, not verified: a user the policy refuses then gets its 403 rather than the
             * verification notice, so the policy alone decides who reads the log.
             */
            return (string) preg_replace(
                "/(Route::middleware\\(\\['auth', 'verified'\\]\\)->group\\(function \\(\\) \\{\n(?:.*\n)*?\\}\\);\n)/",
                "$1\nRoute::middleware('auth')->group(function () {\n    Route::resource('audit-entries', AuditEntryController::class)->only(['index']);\n});\n",
                $code,
                1,
            );
        }, need: ["'audit-entries'", '// kit:routes'], hint: "register Route::resource('audit-entries', AuditEntryController::class)->only(['index']) for signed-in users, and put // kit:routes as the last line of the group new pages belong in");

        /** @var array<string, string> $kitKeys */
        $kitKeys = json_decode((string) file_get_contents($this->kitPath.'/lang/en.json'), true);
        $locales = glob($this->path('lang/*.json')) ?: [];

        if ($locales === []) {
            $locales = [$this->path('lang/en.json')];
        }

        foreach ($locales as $file) {
            $relative = substr($file, strlen($this->projectPath) + 1);
            /** @var array<string, string> $current */
            $current = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?? []) : [];
            $merged = $current + $kitKeys;

            if (count($merged) !== count($current)) {
                ksort($merged);
                $this->put($relative, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            }
        }

        return $this->finish();
    }

    /**
     * The JavaScript package manager the project chose at `laravel new`, read from its lockfile.
     */
    public function packageManager(): string
    {
        return match (true) {
            is_file($this->path('pnpm-lock.yaml')) => 'pnpm',
            is_file($this->path('bun.lock')) || is_file($this->path('bun.lockb')) => 'bun',
            default => 'npm',
        };
    }

    private function start(): void
    {
        $this->result = ['changed' => [], 'manual' => []];
    }

    /**
     * @return array{changed: list<string>, manual: list<string>}
     */
    private function finish(): array
    {
        return ['changed' => array_values(array_unique($this->result['changed'])), 'manual' => array_values(array_unique($this->result['manual']))];
    }

    /**
     * Rewrites one file through $change. When the result still lacks a needle of $need, the
     * starter kit's code was not where the step looked, so the file is left alone and $hint
     * goes to the report as work by hand.
     *
     * @param  callable(string): string  $change
     * @param  list<string>  $need
     */
    private function edit(string $relative, callable $change, array $need = [], string $hint = ''): void
    {
        if (! is_file($this->path($relative))) {
            if ($need !== []) {
                $this->result['manual'][] = sprintf('%s is missing: %s', $relative, $hint);
            }

            return;
        }

        $before = (string) file_get_contents($this->path($relative));
        $after = $change($before);

        foreach ($need as $needle) {
            if (! str_contains($after, $needle)) {
                $this->result['manual'][] = sprintf('%s: %s', $relative, $hint);

                return;
            }
        }

        if ($after !== $before) {
            $this->put($relative, $after);
        }
    }

    private function put(string $relative, string $contents): void
    {
        $path = $this->path($relative);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $contents);
        $this->result['changed'][] = $relative;
    }

    private function path(string $relative): string
    {
        return $this->projectPath.'/'.$relative;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $relative): array
    {
        $decoded = json_decode((string) @file_get_contents($this->path($relative)), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeJson(string $relative, array $data): void
    {
        $this->put($relative, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    /**
     * Drops sessions, which now points at users, before users, so the migration rolls back.
     */
    private static function dropSessionsFirst(string $code): string
    {
        if (preg_match("/(\\n(\\s*)Schema::dropIfExists\\('users'\\);)((?:\\n\\s*Schema::dropIfExists\\('[a-z_]+'\\);)*)/", $code, $match) !== 1) {
            return $code;
        }

        return str_replace($match[0], $match[3].$match[1], $code);
    }

    /**
     * Composer keeps php and the extensions above the packages.
     */
    private static function isPlatform(string $package): bool
    {
        return $package === 'php' || str_starts_with($package, 'ext-');
    }

    private function major(string $constraint): string
    {
        return preg_match('/(\d+)(?:\.(\d+))?/', $constraint, $parts) === 1
            ? ($parts[1] === '0' ? '0.'.($parts[2] ?? '0') : $parts[1])
            : $constraint;
    }

    /**
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private function filesUnder(string $relative, array $extensions): array
    {
        $root = $this->path($relative);

        if (! is_dir($root)) {
            return [];
        }

        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && in_array($file->getExtension(), $extensions, true)) {
                $files[] = substr($file->getPathname(), strlen($this->projectPath) + 1);
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return array<string, string> project-relative path => the kit's file
     */
    private function kitFilesIn(string $folder): array
    {
        $root = $this->kitPath.'/'.$folder;
        $files = [];

        if (! is_dir($root)) {
            return [];
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $files[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function migrationsCreating(array $tables): array
    {
        $migrations = [];

        foreach (glob($this->path('database/migrations/*.php')) ?: [] as $file) {
            $code = (string) file_get_contents($file);

            foreach ($tables as $table) {
                if (str_contains($code, "Schema::create('{$table}'")) {
                    $migrations[] = substr($file, strlen($this->projectPath) + 1);

                    break;
                }
            }
        }

        return $migrations;
    }
}
