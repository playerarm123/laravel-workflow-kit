<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Setup\ProjectSetup;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Setup\UiComponents;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\KitInstaller;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Adds shadcn components through the project's own shadcn CLI, then undoes what the CLI gets
 * wrong for a kit project, so `shadcn add` stays the way components arrive.
 *
 * The CLI installs every package a component lists (the `cn` package among them, which the
 * stack forbids), with whichever package manager it detects, and asks before it overwrites a
 * file. Here the command decides instead: it reads the components' packages with `shadcn view`
 * first and stops on one stack.md does not list, runs `shadcn add`, then puts package.json, the
 * lockfiles and every file it did not mean to change back, installs the packages it settled on
 * with the project's own package manager, and fixes the imports in the files the CLI wrote.
 */
#[Signature('kit:ui-add
    {components* : The shadcn components to add, e.g. command popover}
    {--overwrite=* : A file the CLI may replace, by its path or its trailing path (ui/dialog.tsx)}
    {--allow=* : A package stack.md does not list, approved for this project}
    {--dry-run : Print what would be added and installed, and change nothing}')]
#[Description('Add shadcn components the way the workflow kit\'s stack wants them')]
class KitUiAddCommand extends Command
{
    /** The files the command, not the CLI, decides about. */
    private const array PACKAGE_FILES = [
        'package.json',
        'package-lock.json',
        'npm-shrinkwrap.json',
        'pnpm-lock.yaml',
        'pnpm-workspace.yaml',
        'yarn.lock',
        'bun.lock',
        'bun.lockb',
        'deno.lock',
        'components.json',
    ];

    /** The folders whose files are compared before and after the CLI runs. */
    private const array WATCHED = ['resources', 'app', 'config', 'database', 'lang', 'routes', 'tests'];

    /** Folders under the watched ones that are never read. */
    private const array SKIPPED = ['node_modules', 'vendor', '.git'];

    /** How many rounds of registryDependencies the plan follows before it stops. */
    private const int MAX_DEPTH = 5;

    public function handle(): int
    {
        $shadcn = base_path('node_modules/.bin/shadcn');

        if (! is_file($shadcn)) {
            $this->components->error('The project\'s shadcn CLI is missing (node_modules/.bin/shadcn). Run `php artisan kit:setup` or install the JavaScript packages first.');

            return self::FAILURE;
        }

        if (! is_file(base_path('components.json'))) {
            $this->components->error('components.json is missing. Run `npx shadcn init` the way the starter kit does, then try again.');

            return self::FAILURE;
        }

        /** @var list<string> $requested */
        $requested = array_values(array_unique((array) $this->argument('components')));
        $components = $this->readJson('components.json');
        $tsconfig = UiComponents::decodeJsonc((string) @file_get_contents(base_path('tsconfig.json')));

        $items = $this->view($shadcn, $requested);

        if ($items === null) {
            return self::FAILURE;
        }

        $manager = ProjectSetup::packageManagerOf(base_path());
        $settled = UiComponents::settle($items, $this->readJson('package.json'), WorkflowKit::stack()['npm'], array_values((array) $this->option('allow')));
        $owned = (new KitInstaller(WorkflowKit::kitPath(), base_path()))->ownedPaths();

        if ($this->option('dry-run')) {
            $this->plan($items, $settled, $components, $tsconfig, $owned, $manager);

            return self::SUCCESS;
        }

        if ($settled['awaiting'] !== []) {
            $this->components->error('These packages are not in stack.md, and stack.md asks the user before one is added:');
            $this->components->bulletList($settled['awaiting']);
            $this->line(sprintf('  Once the user approves, run it again with %s, and add them to stack.md and stackSpec().', implode(' ', array_map(fn (string $name): string => '--allow='.$name, $settled['awaiting']))));

            return self::FAILURE;
        }

        foreach ((array) $this->option('overwrite') as $named) {
            foreach ($owned as $path) {
                if (UiComponents::isNamed($path, [(string) $named])) {
                    $this->components->warn(sprintf('%s is a kit file (kit-files): it stays as the kit ships it, --overwrite or not.', $path));
                }
            }
        }

        $packages = $this->snapshot(self::PACKAGE_FILES);
        $files = $this->snapshotTree();

        try {
            $this->steer($manager);
            $added = Process::path(base_path())->forever()->run([$shadcn, 'add', ...$requested, '--yes', '--overwrite']);
        } finally {
            $this->restore($packages);
            $report = $this->settleFiles($files, $components, $tsconfig, $owned);
        }

        if ($added->failed()) {
            $this->output->write($added->output().$added->errorOutput());
            $this->components->error('shadcn add failed. package.json, the lockfiles and every file it changed are back as they were.');

            return self::FAILURE;
        }

        $utils = UiComponents::utilsAlias($components);

        foreach ($report['written'] as $relative) {
            if (preg_match('/\.(tsx?|jsx?)$/', $relative) === 1) {
                $code = (string) file_get_contents(base_path($relative));
                $fixed = UiComponents::fix($code, $utils);

                if ($fixed !== $code) {
                    file_put_contents(base_path($relative), $fixed);
                }
            }
        }

        $installed = $this->install($manager, $settled);
        $this->format($report['written']);
        $this->report($report, $settled, $installed, $manager);

        return $installed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The registry items the components need, their registryDependencies followed.
     *
     * @param  list<string>  $requested
     * @return list<array<string, mixed>>|null
     */
    private function view(string $shadcn, array $requested): ?array
    {
        $items = [];
        $seen = [];
        $queue = $requested;

        for ($depth = 0; $queue !== [] && $depth < self::MAX_DEPTH; $depth++) {
            $result = Process::path(base_path())->run([$shadcn, 'view', ...$queue]);
            $decoded = json_decode($result->output(), true);

            if ($result->failed() || ! is_array($decoded)) {
                $this->components->error(sprintf('shadcn view %s failed: %s', implode(' ', $queue), trim($result->errorOutput().$result->output())));

                return null;
            }

            array_push($seen, ...$queue);
            $queue = [];

            foreach ($decoded as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $name = (string) ($item['name'] ?? '');

                if (isset($items[$name])) {
                    continue;
                }

                $items[$name] = $item;
                $seen[] = $name;

                foreach ((array) ($item['registryDependencies'] ?? []) as $dependency) {
                    $dependency = (string) $dependency;

                    if (! in_array($dependency, $seen, true) && ! in_array($dependency, $queue, true)) {
                        $queue[] = $dependency;
                    }
                }
            }
        }

        return array_values($items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array{install: list<string>, installDev: list<string>, dropped: array<string, string>, awaiting: list<string>}  $settled
     * @param  array<string, mixed>  $components
     * @param  array<string, mixed>  $tsconfig
     * @param  list<string>  $owned
     */
    private function plan(array $items, array $settled, array $components, array $tsconfig, array $owned, string $manager): void
    {
        $this->components->info('Dry run: nothing is written or installed.');

        $files = [];

        foreach ($items as $item) {
            foreach ((array) ($item['files'] ?? []) as $file) {
                $target = UiComponents::targetOf((array) $file, $components, $tsconfig);
                $state = match (true) {
                    in_array($target, $owned, true) => '<fg=yellow>kit file, kept</>',
                    ! is_file(base_path($target)) => '<fg=green>create</>',
                    UiComponents::isNamed($target, array_values((array) $this->option('overwrite'))) => '<fg=yellow>overwrite</>',
                    default => '<fg=gray>exists, kept</>',
                };
                $files[$target] = $state;
            }
        }

        $this->components->twoColumnDetail('<fg=white;options=bold>Items</>', implode(', ', array_map(fn (array $item): string => (string) ($item['name'] ?? '?'), $items)));

        foreach ($files as $target => $state) {
            $this->components->twoColumnDetail($target, $state);
        }

        $this->packages($settled, $manager, null);
    }

    /**
     * package.json names the project's package manager while the CLI runs, so the CLI installs
     * with it rather than guessing from pnpm-workspace.yaml (package.json is restored after).
     */
    private function steer(string $manager): void
    {
        $package = $this->readJson('package.json');

        if ($package === [] || isset($package['packageManager'])) {
            return;
        }

        $version = trim(Process::path(base_path())->run([$manager, '--version'])->output());

        if (preg_match('/^\d+\.\d+\.\d+/', $version, $match) !== 1) {
            return;
        }

        $package['packageManager'] = $manager.'@'.$match[0];
        file_put_contents(base_path('package.json'), json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    /**
     * Sorts every file the CLI changed: a file under the components.json aliases it created stays,
     * a file that existed goes back unless `--overwrite` names it, a kit file always goes back,
     * and anything outside the aliases goes back, except the Tailwind CSS file components.json
     * names, where a component's CSS variables belong.
     *
     * @param  array<string, string>  $before
     * @param  array<string, mixed>  $components
     * @param  array<string, mixed>  $tsconfig
     * @param  list<string>  $owned
     * @return array{written: list<string>, skipped: list<string>, kit: list<string>, reverted: list<string>}
     */
    private function settleFiles(array $before, array $components, array $tsconfig, array $owned): array
    {
        $after = $this->snapshotTree();
        $roots = UiComponents::aliasRoots($components, $tsconfig);
        $css = $components['tailwind']['css'] ?? null;
        $overwrite = array_values((array) $this->option('overwrite'));
        $report = ['written' => [], 'skipped' => [], 'kit' => [], 'reverted' => []];

        foreach ($after as $relative => $contents) {
            $existed = array_key_exists($relative, $before);

            if ($existed && $before[$relative] === $contents) {
                continue;
            }

            $underAlias = array_filter($roots, fn (string $root): bool => str_starts_with($relative, $root.'/')) !== [];
            $verdict = match (true) {
                in_array($relative, $owned, true) => 'kit',
                $relative === $css => 'written',
                ! $underAlias => 'reverted',
                ! $existed => 'written',
                UiComponents::isNamed($relative, $overwrite) => 'written',
                default => 'skipped',
            };

            if ($verdict !== 'written') {
                $existed ? file_put_contents(base_path($relative), $before[$relative]) : unlink(base_path($relative));
            }

            $report[$verdict][] = $relative;
        }

        foreach (array_diff_key($before, $after) as $relative => $contents) {
            $this->put($relative, $contents);
            $report['reverted'][] = $relative;
        }

        return $report;
    }

    /**
     * Installs the settled packages with the package manager the project's lockfile names. With
     * nothing to add it still installs, so node_modules matches the lockfile put back.
     *
     * @param  array{install: list<string>, installDev: list<string>, dropped: array<string, string>, awaiting: list<string>}  $settled
     */
    private function install(string $manager, array $settled): bool
    {
        $add = $manager === 'npm' ? 'install' : 'add';
        $commands = [];

        if ($settled['install'] !== []) {
            $commands[] = [$manager, $add, ...$settled['install']];
        }

        if ($settled['installDev'] !== []) {
            $commands[] = [$manager, $add, '-D', ...$settled['installDev']];
        }

        if ($commands === []) {
            $commands[] = [$manager, 'install'];
        }

        foreach ($commands as $command) {
            $this->components->info('Running '.implode(' ', $command));

            $result = Process::path(base_path())->forever()->run($command, function (string $type, string $output): void {
                $this->output->write($output);
            });

            if ($result->failed()) {
                $this->components->error(sprintf('[%s] failed. The components are written; fix what it says, then run the install again.', implode(' ', $command)));

                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $written
     */
    private function format(array $written): void
    {
        $prettier = base_path('node_modules/.bin/prettier');
        $paths = array_values(array_filter($written, fn (string $path): bool => preg_match('/\.(tsx?|jsx?)$/', $path) === 1));

        if ($paths === [] || ! is_file($prettier)) {
            return;
        }

        $result = Process::path(base_path())->run([$prettier, '--write', ...$paths]);

        if ($result->failed()) {
            $this->components->warn('prettier could not format the written files: '.trim($result->errorOutput()));
        }
    }

    /**
     * @param  array{written: list<string>, skipped: list<string>, kit: list<string>, reverted: list<string>}  $report
     * @param  array{install: list<string>, installDev: list<string>, dropped: array<string, string>, awaiting: list<string>}  $settled
     */
    private function report(array $report, array $settled, bool $installed, string $manager): void
    {
        $this->newLine();

        $sections = [
            ['Files written', $report['written'], 'green'],
            ['Files skipped: they exist, name one with --overwrite to replace it', $report['skipped'], 'yellow'],
            ['Kit files kept as the kit ships them', $report['kit'], 'yellow'],
            ['Changes outside the components.json aliases, undone', $report['reverted'], 'yellow'],
        ];

        foreach ($sections as [$label, $paths, $color]) {
            if ($paths !== []) {
                $this->components->twoColumnDetail(sprintf('<fg=%s>%s</>', $color, $label));

                foreach ($paths as $path) {
                    $this->line('  '.$path);
                }
            }
        }

        $this->packages($settled, $manager, $installed);
    }

    /**
     * @param  array{install: list<string>, installDev: list<string>, dropped: array<string, string>, awaiting: list<string>}  $settled
     */
    private function packages(array $settled, string $manager, ?bool $installed): void
    {
        $verb = $installed === null ? 'To install' : ($installed ? 'Installed' : 'Not installed');
        $all = [...$settled['install'], ...array_map(fn (string $name): string => $name.' (dev)', $settled['installDev'])];

        $this->components->twoColumnDetail(sprintf('%s with %s', $verb, $manager), $all === [] ? '<fg=gray>none</>' : implode(', ', $all));

        foreach ($settled['dropped'] as $name => $reason) {
            $this->components->twoColumnDetail(sprintf('<fg=yellow>Dropped %s</>', $name), $reason);
        }

        if ($settled['awaiting'] !== []) {
            $this->components->twoColumnDetail('<fg=red>Awaiting --allow</>', implode(', ', $settled['awaiting']));
        }
    }

    /**
     * @param  list<string>  $relatives
     * @return array<string, string|null> each file's contents, null when it is missing
     */
    private function snapshot(array $relatives): array
    {
        $snapshot = [];

        foreach ($relatives as $relative) {
            $snapshot[$relative] = is_file(base_path($relative)) ? (string) file_get_contents(base_path($relative)) : null;
        }

        return $snapshot;
    }

    /**
     * Puts every file back as the snapshot holds it, and deletes a file that was not there.
     *
     * @param  array<string, string|null>  $snapshot
     */
    private function restore(array $snapshot): void
    {
        foreach ($snapshot as $relative => $contents) {
            if ($contents !== null) {
                if (! is_file(base_path($relative)) || file_get_contents(base_path($relative)) !== $contents) {
                    $this->put($relative, $contents);
                }
            } elseif (is_file(base_path($relative))) {
                unlink(base_path($relative));
            }
        }
    }

    /**
     * The contents of every file in the watched folders and at the project root.
     *
     * @return array<string, string>
     */
    private function snapshotTree(): array
    {
        $files = [];

        foreach (glob(base_path('{,.}*'), GLOB_BRACE) ?: [] as $path) {
            $relative = basename($path);

            if (is_file($path) && ! in_array($relative, self::PACKAGE_FILES, true)) {
                $files[$relative] = (string) file_get_contents($path);
            }
        }

        foreach (self::WATCHED as $folder) {
            $root = base_path($folder);

            if (! is_dir($root)) {
                continue;
            }

            $directories = new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                fn (SplFileInfo $file): bool => ! ($file->isDir() && in_array($file->getFilename(), self::SKIPPED, true)),
            );

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator($directories) as $file) {
                if ($file->isFile()) {
                    $files[substr($file->getPathname(), strlen(base_path()) + 1)] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        ksort($files);

        return $files;
    }

    private function put(string $relative, string $contents): void
    {
        $path = base_path($relative);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $contents);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $relative): array
    {
        $decoded = json_decode((string) @file_get_contents(base_path($relative)), true);

        return is_array($decoded) ? $decoded : [];
    }
}
