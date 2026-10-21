<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Setup;

/**
 * The starter kit's CI workflow (.github/workflows/tests.yml), brought to the project kit:setup
 * made: `composer setup` migrates and `composer ci:check` runs every suite, on the engine
 * .env.example names rather than the sqlite file the starter kit ships, and with the browser the
 * Browser suite drives.
 *
 * The edits read the starter kit's shape: one job whose steps run `composer setup` and then
 * `composer ci:check`. A workflow of another shape is left as it is.
 */
final class CiWorkflow
{
    public const string PATH = '.github/workflows/tests.yml';

    /** The image of each engine's service, as stackSpec()['ci_database_images'] reads it. */
    public const array IMAGES = [
        'pgsql' => 'postgres:17',
        'mysql' => 'mysql:8.4',
        'mariadb' => 'mariadb:11.4',
    ];

    public const string PLAYWRIGHT = 'npx playwright install --with-deps chromium';

    private const string SETUP_STEP = '/^([ \t]*)- (?:name: [^\n]*\n[ \t]+)?run: composer setup[ \t]*\n/m';

    /**
     * Adds the engine's service, the step that creates the test database and the step that
     * installs Chromium, and runs the job on the PHP the stack requires. Each is added only when
     * the workflow lacks it.
     */
    public static function bringAlong(string $yaml, string $database, string $testDatabase): string
    {
        if (preg_match(self::SETUP_STEP, $yaml, $setup, PREG_OFFSET_CAPTURE) !== 1 || ! str_contains($yaml, 'composer ci:check')) {
            return $yaml;
        }

        $step = $setup[1][0];

        if (! self::declaresService($yaml, $database)) {
            $yaml = self::withService($yaml, $database);
        }

        if (! str_contains($yaml, 'playwright install')) {
            $yaml = (string) preg_replace(
                self::SETUP_STEP,
                "$0\n{$step}- name: Install Chromium for the Browser tests\n{$step}  run: ".self::PLAYWRIGHT."\n",
                $yaml,
                1,
            );
        }

        if (! str_contains($yaml, "create database {$testDatabase}")) {
            $yaml = (string) preg_replace(
                self::SETUP_STEP,
                "{$step}- name: Create the {$testDatabase} database phpunit.xml names\n{$step}  run: ".self::createDatabase($database, $testDatabase)."\n\n$0",
                $yaml,
                1,
            );
        }

        return (string) preg_replace_callback(
            "/^([ \\t]*php-version: )(['\"]?)(\\d+)\\.(\\d+)\\2[ \\t]*$/m",
            fn (array $match): string => version_compare($match[3].'.'.$match[4], '8.4', '<') ? "{$match[1]}{$match[2]}8.4{$match[2]}" : $match[0],
            $yaml,
            1,
        );
    }

    /**
     * Whether the workflow declares a service on the engine's image.
     */
    public static function declaresService(string $yaml, string $database): bool
    {
        $name = explode(':', self::IMAGES[$database])[0];

        return preg_match('/^[ \t]*image:[ \t]*[\'"]?(?:docker\.io\/)?(?:library\/)?'.preg_quote($name, '/').'(?::|[\'"\s]|$)/m', $yaml) === 1;
    }

    private static function withService(string $yaml, string $database): string
    {
        if (preg_match('/^[ \t]*services:[ \t]*$/m', $yaml) === 1 || preg_match('/^([ \t]+)steps:[ \t]*\n/m', $yaml, $steps) !== 1) {
            return $yaml;
        }

        $job = $steps[1];
        $unit = str_repeat(' ', max(1, intdiv(strlen($job), 2)));
        $lines = self::service($database);
        $block = "{$job}services:\n".implode('', array_map(
            fn (string $line): string => $line === '' ? "\n" : $job.$unit.str_replace("\t", $unit, $line)."\n",
            $lines,
        ))."\n";

        return (string) preg_replace('/^[ \t]+steps:[ \t]*\n/m', $block.'$0', $yaml, 1);
    }

    /**
     * The service, one tab per level under `services:`, on the database, user and empty password
     * .env.example names.
     *
     * @return list<string>
     */
    private static function service(string $database): array
    {
        [$env, $port, $health] = match ($database) {
            'pgsql' => [['POSTGRES_USER: postgres', 'POSTGRES_DB: laravel', 'POSTGRES_HOST_AUTH_METHOD: trust'], '5432', 'pg_isready'],
            'mysql' => [['MYSQL_DATABASE: laravel', "MYSQL_ALLOW_EMPTY_PASSWORD: 'yes'"], '3306', '"mysqladmin ping"'],
            default => [['MARIADB_DATABASE: laravel', "MARIADB_ALLOW_EMPTY_ROOT_PASSWORD: 'yes'"], '3306', '"healthcheck.sh --connect --innodb_initialized"'],
        };

        return [
            explode(':', self::IMAGES[$database])[0].':',
            "\timage: ".self::IMAGES[$database],
            "\tenv:",
            ...array_map(fn (string $line): string => "\t\t{$line}", $env),
            "\tports:",
            "\t\t- {$port}:{$port}",
            "\toptions: >-",
            "\t\t--health-cmd {$health}",
            "\t\t--health-interval 5s",
            "\t\t--health-timeout 5s",
            "\t\t--health-retries 10",
        ];
    }

    private static function createDatabase(string $database, string $testDatabase): string
    {
        return $database === 'pgsql'
            ? "psql -h 127.0.0.1 -U postgres -c 'create database {$testDatabase}'"
            : "mysql -h 127.0.0.1 -u root -e 'create database {$testDatabase}'";
    }
}
