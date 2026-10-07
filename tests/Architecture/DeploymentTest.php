<?php

require_once __DIR__.'/Support/rules.php';

/**
 * The machine-checked half of deployment.md — change the two together.
 *
 * Every project must deploy to both Plesk and Laravel Cloud, so the code may assume
 * neither: no fixed disk, no local path for user files, no driver-only feature.
 * `generated_js` lists the folders Wayfinder writes; they mirror routes and are not
 * hand-written code.
 *
 * @return array{
 *     s3_package: string,
 *     default_drivers: array<string, string>,
 *     forbidden: list<string>,
 *     required_env_keys: list<string>,
 *     generated_js: list<string>,
 * }
 */
function deploymentSpec(): array
{
    return [
        's3_package' => 'league/flysystem-aws-s3-v3',
        'default_drivers' => [
            'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'database',
            'SESSION_DRIVER' => 'database',
        ],
        'forbidden' => ['laravel/octane'],
        'required_env_keys' => ['NIGHTWATCH_TOKEN'],
        'generated_js' => ['resources/js/actions', 'resources/js/routes', 'resources/js/wayfinder'],
    ];
}

/**
 * @return list<string>
 */
function deploymentAppFiles(): array
{
    return ruleSourceFiles('app', ['php']);
}

/**
 * @return list<string>
 */
function deploymentJsFiles(): array
{
    return ruleSourceFiles('resources/js', ['ts', 'tsx', 'js', 'jsx'], deploymentSpec()['generated_js']);
}

describe('deployment', function () {
    it('can store files on s3, which Laravel Cloud requires', function () {
        $violations = [];
        $filesystems = (string) @file_get_contents(ruleProjectPath('config/filesystems.php'));

        if (! array_key_exists(deploymentSpec()['s3_package'], ruleComposerReadRequire())) {
            $violations[] = ['subject' => deploymentSpec()['s3_package'], 'message' => 'must be in composer.json "require"'];
        }

        if (preg_match("/'driver'\s*=>\s*'s3'/", $filesystems) !== 1) {
            $violations[] = ['subject' => 'config/filesystems.php', 'message' => 'has no disk with the s3 driver'];
        }

        expect(ruleUnexcused('deployment', 's3-ready', $violations))->toBe([]);
    });

    it('takes the default disk from the environment', function () {
        $filesystems = (string) @file_get_contents(ruleProjectPath('config/filesystems.php'));
        $violations = preg_match("/'default'\s*=>\s*env\(\s*'FILESYSTEM_DISK'/", $filesystems) === 1 ? [] : [[
            'subject' => 'config/filesystems.php',
            'message' => "'default' must read env('FILESYSTEM_DISK')",
        ]];

        expect(ruleUnexcused('deployment', 'default-disk', $violations))->toBe([]);
    });

    it('never names a disk in application code', function () {
        $violations = ruleCodeMatches(
            deploymentAppFiles(),
            '/Storage::disk\(\s*[\'"]/',
            'names a disk literally — read the disk name from config',
        );

        expect(ruleUnexcused('deployment', 'disk-literal', $violations))->toBe([]);
    });

    it('never builds a local filesystem path in application code', function () {
        $violations = ruleCodeMatches(
            deploymentAppFiles(),
            '/\b(public_path|storage_path)\s*\(/',
            'builds a local path — go through a Storage disk, local paths do not survive a Laravel Cloud deploy',
        );

        expect(ruleUnexcused('deployment', 'local-path', $violations))->toBe([]);
    });

    it('never hardcodes the /storage/ url', function () {
        $violations = ruleCodeMatches(
            [...deploymentAppFiles(), ...deploymentJsFiles()],
            '#[\'"`]/storage/#',
            'hardcodes /storage/ — take the url from Storage::url() on the server',
        );

        expect(ruleUnexcused('deployment', 'storage-url', $violations))->toBe([]);
    });

    it('defaults queue, cache and session to the database driver', function () {
        $violations = [];

        foreach (deploymentSpec()['default_drivers'] as $key => $driver) {
            $value = ruleEnvValue('.env.example', $key);

            if ($value !== $driver) {
                $violations[] = [
                    'subject' => '.env.example '.$key,
                    'message' => sprintf('is "%s", expected "%s" — switch to redis per environment, not in the default', $value ?? '(unset)', $driver),
                ];
            }
        }

        expect(ruleUnexcused('deployment', 'drivers', $violations))->toBe([]);
    });

    it('uses no cache tags, which the database driver cannot store', function () {
        $violations = ruleCodeMatches(
            deploymentAppFiles(),
            '/Cache::tags\s*\(|cache\(\)\s*->\s*tags\s*\(/',
            'uses cache tags',
        );

        expect(ruleUnexcused('deployment', 'cache-tags', $violations))->toBe([]);
    });

    it('never reaches for the Redis facade, since redis is optional', function () {
        $violations = ruleCodeMatches(
            deploymentAppFiles(),
            '/Illuminate\\\\Support\\\\Facades\\\\Redis\b|\bRedis::/',
            'talks to Redis directly',
        );

        expect(ruleUnexcused('deployment', 'redis-facade', $violations))->toBe([]);
    });

    it('runs horizon only on top of a redis queue', function () {
        $violations = [];

        if (array_key_exists('laravel/horizon', ruleComposerDeclared())) {
            if (! is_file(ruleProjectPath('config/horizon.php'))) {
                $violations[] = ['subject' => 'config/horizon.php', 'message' => 'is missing while laravel/horizon is installed'];
            }

            if (preg_match("/'redis'\s*=>/", (string) @file_get_contents(ruleProjectPath('config/queue.php'))) !== 1) {
                $violations[] = ['subject' => 'config/queue.php', 'message' => 'has no redis connection for horizon to run on'];
            }
        }

        expect(ruleUnexcused('deployment', 'horizon-redis', $violations))->toBe([]);
    });

    it('installs no long-running server that Plesk cannot host', function () {
        $violations = ruleForbiddenFound(ruleComposerDeclared(), deploymentSpec()['forbidden'], 'it replaces PHP-FPM with a long-running server');

        expect(ruleUnexcused('deployment', 'forbidden', $violations))->toBe([]);
    });

    it('lists every environment key a deploy target must set', function () {
        $violations = [];

        foreach (deploymentSpec()['required_env_keys'] as $key) {
            if (ruleEnvValue('.env.example', $key) === null) {
                $violations[] = ['subject' => '.env.example '.$key, 'message' => 'is missing — add it with an empty value'];
            }
        }

        expect(ruleUnexcused('deployment', 'env-keys', $violations))->toBe([]);
    });
});
