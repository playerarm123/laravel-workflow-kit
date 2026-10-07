<?php

require_once __DIR__.'/Support/rules.php';

/**
 * The machine-checked half of stack.md — change the two together.
 *
 * `required` packages must be declared and installed on the locked line. `optional`
 * packages are not required, but when a project installs one it must sit on the locked
 * line. `forbidden` packages are the alternatives the stack already answered for.
 * A version is a major (`13`) or, on a 0.x line, a major.minor (`0.1`).
 *
 * @return array{
 *     php: string,
 *     composer: array{required: array<string, string>, optional: array<string, string>, forbidden: list<string>},
 *     npm: array{required: array<string, string>, optional: array<string, string>, forbidden: list<string>},
 *     databases: list<string>,
 *     redis_client: string,
 *     icon_library: string,
 * }
 */
function stackSpec(): array
{
    return [
        'php' => '8.4',
        'composer' => [
            'required' => [
                'laravel/framework' => '13',
                'inertiajs/inertia-laravel' => '3',
                'laravel/fortify' => '1',
                'spatie/laravel-data' => '4',
                'laravel/wayfinder' => '0.1',
                'laravel/nightwatch' => '1',
                'pestphp/pest' => '5',
                'pestphp/pest-plugin-laravel' => '5',
                'pestphp/pest-plugin-browser' => '5',
                'larastan/larastan' => '3',
                'laravel/pint' => '1',
                'playerarm123/laravel-workflow-kit' => '0.1',
            ],
            'optional' => [
                'intervention/image' => '4',
                'maatwebsite/excel' => '4',
                'mpdf/mpdf' => '8',
                'laravel/horizon' => '5',
                'pusher/pusher-php-server' => '7',
                'laravel/slack-notification-channel' => '3',
                'laravel-notification-channels/telegram' => '8',
            ],
            'forbidden' => [
                'livewire/livewire',
                'sentry/sentry-laravel',
                'bugsnag/*',
                'spatie/laravel-flare',
                'opcodesio/log-viewer',
                'spatie/image',
                'imagine/imagine',
                'predis/predis',
                'spatie/simple-excel',
                'rap2hpoutre/fast-excel',
                'phpoffice/phpspreadsheet',
                'barryvdh/laravel-dompdf',
                'dompdf/dompdf',
                'spatie/laravel-pdf',
                'spatie/browsershot',
                'knplabs/knp-snappy',
                'barryvdh/laravel-snappy',
                'linecorp/line-bot-sdk',
                'spatie/laravel-activitylog',
                'owen-it/laravel-auditing',
            ],
        ],
        'npm' => [
            'required' => [
                '@inertiajs/react' => '3',
                '@inertiajs/vite' => '3',
                '@laravel/passkeys' => '0.2',
                '@laravel/vite-plugin-wayfinder' => '0.1',
                'react' => '19',
                'react-dom' => '19',
                'typescript' => '5',
                'vite' => '8',
                'tailwindcss' => '4',
                '@tailwindcss/vite' => '4',
                'laravel-vite-plugin' => '3',
                'shadcn' => '4',
                'radix-ui' => '1',
                'lucide-react' => '1',
                'eslint' => '9',
                'prettier' => '3',
                'prettier-plugin-tailwindcss' => '0.6',
                'playwright' => '1',
            ],
            'optional' => [
                'clsx' => '2',
                'tailwind-merge' => '3',
                '@tanstack/react-table' => '9',
                'sonner' => '2',
                'date-fns' => '4',
                '@xyflow/react' => '12',
                '@dagrejs/dagre' => '3',
                'laravel-echo' => '2',
                'pusher-js' => '8',
            ],
            'forbidden' => [
                'vue',
                'cn',
                'classnames',
                'svelte',
                'alpinejs',
                'jquery',
                'axios',
                'ky',
                'swr',
                '@tanstack/react-query',
                'moment',
                'dayjs',
                'luxon',
                'react-toastify',
                'react-hot-toast',
                'ag-grid-*',
                'react-data-table-component',
                '@phosphor-icons/react',
                'react-icons',
                '@heroicons/react',
                '@tabler/icons-react',
                '@radix-ui/*',
                '@headlessui/react',
                '@mui/*',
                '@chakra-ui/*',
                'antd',
                '@mantine/*',
                'reactflow',
                'dagre',
                'elkjs',
                'mermaid',
                'cytoscape',
                'jointjs',
            ],
        ],
        'databases' => ['pgsql', 'mysql', 'mariadb'],
        'redis_client' => 'phpredis',
        'icon_library' => 'lucide',
    ];
}

/**
 * @param  array<string, string>  $locked
 * @param  array<string, string>  $declared
 * @return list<array{subject: string, message: string}>
 */
function stackMissing(array $locked, array $declared): array
{
    $violations = [];

    foreach (array_keys($locked) as $package) {
        if (! array_key_exists($package, $declared)) {
            $violations[] = ['subject' => $package, 'message' => 'is required but not declared'];
        }
    }

    return $violations;
}

/**
 * @param  array<string, string>  $locked
 * @param  array<string, string>  $declared
 * @param  array<string, string>  $installed
 * @return list<array{subject: string, message: string}>
 */
function stackOffLine(array $locked, array $declared, array $installed, string $lockfile): array
{
    $violations = [];

    foreach ($locked as $package => $line) {
        if (! array_key_exists($package, $declared)) {
            continue;
        }

        if (! array_key_exists($package, $installed)) {
            $violations[] = [
                'subject' => $package,
                'message' => sprintf('is declared but missing from %s — run the install so the lockfile matches', $lockfile),
            ];

            continue;
        }

        if (! ruleVersionMatches($installed[$package], $line)) {
            $violations[] = [
                'subject' => $package,
                'message' => sprintf('found %s, expected %s.x', $installed[$package], $line),
            ];
        }
    }

    return $violations;
}

describe('stack', function () {
    it('declares every required package', function () {
        $violations = [
            ...stackMissing(stackSpec()['composer']['required'], ruleComposerDeclared()),
            ...stackMissing(stackSpec()['npm']['required'], ruleNpmDeclared()),
        ];

        expect(ruleUnexcused('stack', 'required', $violations))->toBe([]);
    });

    it('installs every required package on its locked line', function () {
        $violations = [
            ...stackOffLine(stackSpec()['composer']['required'], ruleComposerDeclared(), ruleComposerInstalled(), 'composer.lock'),
            ...stackOffLine(stackSpec()['npm']['required'], ruleNpmDeclared(), ruleNpmInstalled(), 'package-lock.json'),
        ];

        $php = ruleComposerDeclared()['php'] ?? '';

        if (preg_match('/(\d+)\.(\d+)/', $php, $matches) !== 1 || stackSpec()['php'] !== $matches[1].'.'.$matches[2]) {
            $violations[] = [
                'subject' => 'php',
                'message' => sprintf('constraint is "%s", expected it to start at %s', $php, stackSpec()['php']),
            ];
        }

        expect(ruleUnexcused('stack', 'major', $violations))->toBe([]);
    });

    it('keeps every installed optional package on its locked line', function () {
        $violations = [
            ...stackOffLine(stackSpec()['composer']['optional'], ruleComposerDeclared(), ruleComposerInstalled(), 'composer.lock'),
            ...stackOffLine(stackSpec()['npm']['optional'], ruleNpmDeclared(), ruleNpmInstalled(), 'package-lock.json'),
        ];

        expect(ruleUnexcused('stack', 'optional-major', $violations))->toBe([]);
    });

    it('declares no package the stack already answered for', function () {
        $violations = [
            ...ruleForbiddenFound(ruleComposerDeclared(), stackSpec()['composer']['forbidden'], 'the stack picks another library for this need'),
            ...ruleForbiddenFound(ruleNpmDeclared(), stackSpec()['npm']['forbidden'], 'the stack picks another library for this need'),
        ];

        expect(ruleUnexcused('stack', 'forbidden', $violations))->toBe([]);
    });

    it('talks to redis through phpredis', function () {
        $client = ruleEnvValue('.env.example', 'REDIS_CLIENT');
        $violations = $client === stackSpec()['redis_client'] ? [] : [[
            'subject' => '.env.example REDIS_CLIENT',
            'message' => sprintf('is "%s", expected "%s"', $client ?? '(unset)', stackSpec()['redis_client']),
        ]];

        expect(ruleUnexcused('stack', 'redis-client', $violations))->toBe([]);
    });

    it('declares a supported database and tests on that same engine', function () {
        $declared = ruleEnvValue('.env.example', 'DB_CONNECTION');
        $tested = rulePhpunitEnv('DB_CONNECTION');
        $violations = [];

        if (! in_array($declared, stackSpec()['databases'], true)) {
            $violations[] = [
                'subject' => '.env.example DB_CONNECTION',
                'message' => sprintf('is "%s", expected one of %s', $declared ?? '(unset)', implode(', ', stackSpec()['databases'])),
            ];
        }

        if ($tested !== $declared) {
            $violations[] = [
                'subject' => 'phpunit.xml DB_CONNECTION',
                'message' => sprintf('is "%s", expected the declared engine "%s"', $tested ?? '(unset)', $declared ?? '(unset)'),
            ];
        }

        expect(ruleUnexcused('stack', 'database', $violations))->toBe([]);
    });

    it('generates shadcn components with the stack icon library', function () {
        $library = ruleReadJson('components.json')['iconLibrary'] ?? null;
        $violations = $library === stackSpec()['icon_library'] ? [] : [[
            'subject' => 'components.json iconLibrary',
            'message' => sprintf('is "%s", expected "%s"', is_string($library) ? $library : '(unset)', stackSpec()['icon_library']),
        ]];

        expect(ruleUnexcused('stack', 'icons', $violations))->toBe([]);
    });
});
