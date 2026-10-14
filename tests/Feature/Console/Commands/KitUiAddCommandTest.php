<?php

use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * kit:ui-add on a scratch project of its own, with every process faked: `shadcn view` prints the
 * registry fixture below, `shadcn add` does what shadcn 4.21 did to abz-payment (writes the files,
 * declares `cn`, installs with pnpm, overwrites an existing file), and the install only records
 * what it was asked.
 */
const UI_ADD_UTILS = "export function cn(...inputs: string[]) {\n    return inputs.join(' ');\n}\n";

const UI_ADD_DIALOG = "// the project's own dialog\nexport function Dialog() {}\n";

const UI_ADD_PACKAGE = "{\n    \"private\": true,\n    \"dependencies\": {\n        \"radix-ui\": \"^1.4.3\",\n        \"react\": \"^19.2.0\"\n    }\n}\n";

const UI_ADD_LOCK = "{\n    \"name\": \"project\",\n    \"lockfileVersion\": 3\n}\n";

const UI_ADD_WORKSPACE = "packages: []\n";

/**
 * @return list<array<string, mixed>>
 */
function uiAddRegistry(string $name): array
{
    return match ($name) {
        'command' => [[
            'name' => 'command',
            'type' => 'registry:ui',
            'dependencies' => ['cn', 'cmdk', 'left-pad'],
            'registryDependencies' => ['dialog'],
            'files' => [['path' => 'registry/new-york-v4/ui/command.tsx', 'type' => 'registry:ui', 'content' => '']],
        ]],
        'dialog' => [[
            'name' => 'dialog',
            'type' => 'registry:ui',
            'dependencies' => ['cn', 'radix-ui'],
            'files' => [['path' => 'registry/new-york-v4/ui/dialog.tsx', 'type' => 'registry:ui', 'content' => '']],
        ]],
        default => [],
    };
}

/**
 * What shadcn 4.21's `add command` does to the project: every file of the tree written (one of
 * them a kit file, one outside the aliases), `cn` and `cmdk` declared, pnpm-lock.yaml created
 * and pnpm-workspace.yaml edited.
 */
function uiAddRunCli(string $project): void
{
    $source = "import * as React from \"react\"\nimport { Command as CommandPrimitive } from \"cmdk\"\n\nimport { cn } from \"cn\"\nimport { Dialog } from \"@/components/ui/dialog\"\n\nexport function Command({ className }: { className?: string }) {\n  return <CommandPrimitive className={cn(\"flex\", className)} />\n}\n";

    File::put($project.'/resources/js/components/ui/command.tsx', $source);
    File::put($project.'/resources/js/components/ui/dialog.tsx', "import { cn } from \"cn\"\nexport function Dialog() {}\n");
    File::put($project.'/resources/js/components/ui/date-range-picker.tsx', "// shadcn's own\n");
    File::put($project.'/.env', "CN=1\n");
    File::ensureDirectoryExists($project.'/resources/js/hooks');
    File::put($project.'/resources/js/hooks/use-cmdk.ts', "import { cn } from 'cn';\nexport const x = cn;\n");

    $package = json_decode(File::get($project.'/package.json'), true);
    $package['dependencies']['cn'] = '^0.4.0';
    $package['dependencies']['cmdk'] = '^1.1.1';
    File::put($project.'/package.json', json_encode($package, JSON_PRETTY_PRINT));
    File::put($project.'/package-lock.json', '{"changed": true}');
    File::put($project.'/pnpm-lock.yaml', "lockfileVersion: '9.0'\n");
    File::put($project.'/pnpm-workspace.yaml', "allowBuilds:\n  unrs-resolver: set this to true or false\n");
}

/**
 * @param  list<list<string>>  $ran
 */
function uiAddFake(string $project, array &$ran, bool $addFails = false): void
{
    Process::fake(['*' => function (PendingProcess $process) use ($project, &$ran, $addFails): FakeProcessResult|string {
        $command = array_values(array_map(fn ($part): string => basename((string) $part) === 'shadcn' ? 'shadcn' : (string) $part, (array) $process->command));
        $ran[] = $command;

        if ($command[0] === 'shadcn' && $command[1] === 'view') {
            return json_encode(array_merge(...array_map(uiAddRegistry(...), array_slice($command, 2)))) ?: '[]';
        }

        if ($command[0] === 'shadcn' && $command[1] === 'add') {
            $ran[] = ['packageManager while the CLI ran', (string) (json_decode(File::get($project.'/package.json'), true)['packageManager'] ?? '')];
            uiAddRunCli($project);

            return $addFails ? Process::result(output: 'try a previous version', exitCode: 1) : '';
        }

        if ($command[1] === '--version') {
            return "10.9.4\n";
        }

        return '';
    }]);
}

beforeEach(function () {
    $this->project = storage_path('framework/testing/kit-ui-add-'.bin2hex(random_bytes(4)));
    $this->originalBase = base_path();
    $this->ran = [];

    $files = [
        'components.json' => json_encode([
            'style' => 'new-york',
            'tailwind' => ['css' => 'resources/css/app.css'],
            'iconLibrary' => 'lucide',
            'aliases' => ['components' => '@/components', 'utils' => '@/lib/utils', 'ui' => '@/components/ui', 'lib' => '@/lib', 'hooks' => '@/hooks'],
        ], JSON_PRETTY_PRINT),
        'tsconfig.json' => "{\n    \"compilerOptions\": {\n        // the alias shadcn generates against\n        \"paths\": { \"@/*\": [\"./resources/js/*\"] }\n    }\n}\n",
        'package.json' => UI_ADD_PACKAGE,
        'package-lock.json' => UI_ADD_LOCK,
        'pnpm-workspace.yaml' => UI_ADD_WORKSPACE,
        'node_modules/.bin/shadcn' => '',
        'resources/css/app.css' => "@import 'tailwindcss';\n",
        'resources/js/lib/utils.ts' => UI_ADD_UTILS,
        'resources/js/components/ui/dialog.tsx' => UI_ADD_DIALOG,
        'resources/js/components/ui/date-range-picker.tsx' => "// the kit's copy\n",
    ];

    foreach ($files as $relative => $contents) {
        File::ensureDirectoryExists(dirname($this->project.'/'.$relative));
        File::put($this->project.'/'.$relative, $contents);
    }

    $this->app->setBasePath($this->project);
    $this->read = fn (string $relative): string => File::get($this->project.'/'.$relative);
});

afterEach(function () {
    $this->app->setBasePath($this->originalBase);
    File::deleteDirectory($this->project);
});

it('stops before it changes anything on a package stack.md does not list', function () {
    uiAddFake($this->project, $this->ran);

    $this->artisan('kit:ui-add', ['components' => ['command']])
        ->expectsOutputToContain('--allow=left-pad')
        ->assertFailed();

    expect(array_column($this->ran, 1))->toBe(['view', 'view'])
        ->and(file_exists($this->project.'/resources/js/components/ui/command.tsx'))->toBeFalse();
});

describe('with the package allowed', function () {
    beforeEach(function () {
        uiAddFake($this->project, $this->ran);

        $this->artisan('kit:ui-add', ['components' => ['command'], '--allow' => ['left-pad']])
            ->expectsOutputToContain('Files written')
            ->expectsOutputToContain('Dropped cn')
            ->assertSuccessful();
    });

    it('follows the registry dependencies with shadcn view, then adds with the project\'s own CLI', function () {
        expect(array_slice($this->ran, 0, 2))->toBe([['shadcn', 'view', 'command'], ['shadcn', 'view', 'dialog']])
            ->and($this->ran)->toContain(['shadcn', 'add', 'command', '--yes', '--overwrite']);
    });

    it('names npm in package.json while the CLI runs, so pnpm-workspace.yaml does not send it to pnpm', function () {
        expect($this->ran)->toContain(['packageManager while the CLI ran', 'npm@10.9.4']);
    });

    it('imports cn from the utils alias in every file it wrote', function () {
        expect(($this->read)('resources/js/components/ui/command.tsx'))
            ->toContain("import { cn } from '@/lib/utils'")
            ->not->toContain('"cn"')
            ->and(($this->read)('resources/js/hooks/use-cmdk.ts'))->toContain("import { cn } from '@/lib/utils';");
    });

    it('installs what it settled with the lockfile\'s package manager, and never cn', function () {
        $installs = array_values(array_filter($this->ran, fn (array $command): bool => $command[0] === 'npm' && $command[1] === 'install'));

        expect($installs)->toBe([['npm', 'install', 'cmdk@^1', 'left-pad']])
            ->and(array_filter($this->ran, fn (array $command): bool => in_array('cn', $command, true) || in_array($command[0], ['pnpm', 'bun'], true)))->toBe([]);
    });

    it('leaves package.json and the lockfiles to the install, and removes the lockfile the CLI made', function () {
        expect(($this->read)('package.json'))->toBe(UI_ADD_PACKAGE)
            ->and(($this->read)('package-lock.json'))->toBe(UI_ADD_LOCK)
            ->and(($this->read)('pnpm-workspace.yaml'))->toBe(UI_ADD_WORKSPACE)
            ->and(file_exists($this->project.'/pnpm-lock.yaml'))->toBeFalse();
    });

    it('keeps an existing file and a kit file as they were', function () {
        expect(($this->read)('resources/js/components/ui/dialog.tsx'))->toBe(UI_ADD_DIALOG)
            ->and(($this->read)('resources/js/components/ui/date-range-picker.tsx'))->toBe("// the kit's copy\n");
    });

    it('undoes what the CLI wrote outside the components.json aliases', function () {
        expect(file_exists($this->project.'/.env'))->toBeFalse();
    });
});

it('replaces an existing file --overwrite names, but never a kit file', function () {
    uiAddFake($this->project, $this->ran);

    $this->artisan('kit:ui-add', ['components' => ['command'], '--allow' => ['left-pad'], '--overwrite' => ['ui/dialog.tsx', 'date-range-picker.tsx']])
        ->expectsOutputToContain('is a kit file')
        ->assertSuccessful();

    expect(($this->read)('resources/js/components/ui/dialog.tsx'))->toBe("import { cn } from '@/lib/utils'\nexport function Dialog() {}\n")
        ->and(($this->read)('resources/js/components/ui/date-range-picker.tsx'))->toBe("// the kit's copy\n");
});

it('puts everything back when the CLI fails', function () {
    uiAddFake($this->project, $this->ran, addFails: true);

    $this->artisan('kit:ui-add', ['components' => ['command'], '--allow' => ['left-pad']])
        ->expectsOutputToContain('shadcn add failed')
        ->assertFailed();

    expect(($this->read)('package.json'))->toBe(UI_ADD_PACKAGE)
        ->and(file_exists($this->project.'/pnpm-lock.yaml'))->toBeFalse()
        ->and(($this->read)('resources/js/components/ui/dialog.tsx'))->toBe(UI_ADD_DIALOG)
        ->and(array_filter($this->ran, fn (array $command): bool => $command[0] === 'npm' && $command[1] === 'install'))->toBe([]);
});

it('prints the plan on --dry-run and changes nothing', function () {
    uiAddFake($this->project, $this->ran);

    $this->artisan('kit:ui-add', ['components' => ['command'], '--dry-run' => true])
        ->expectsOutputToContain('resources/js/components/ui/command.tsx')
        ->expectsOutputToContain('Awaiting --allow')
        ->assertSuccessful();

    expect(array_column($this->ran, 1))->toBe(['view', 'view'])
        ->and(($this->read)('package.json'))->toBe(UI_ADD_PACKAGE)
        ->and(file_exists($this->project.'/resources/js/components/ui/command.tsx'))->toBeFalse();
});

it('refuses without the project\'s shadcn CLI', function () {
    File::delete($this->project.'/node_modules/.bin/shadcn');
    Process::fake();

    $this->artisan('kit:ui-add', ['components' => ['command']])
        ->expectsOutputToContain('node_modules/.bin/shadcn')
        ->assertFailed();

    Process::assertNothingRan();
});
