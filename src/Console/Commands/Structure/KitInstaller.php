<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Writes the kit's own files into a project, at the paths the guidelines fix for them.
 *
 * `{kit}/files/{path}` is a file the project keeps exactly as the kit ships it: it is written
 * when it is missing, left alone when it differs (the `kit-files` checks name it), and
 * overwritten only when asked to. `{kit}/scaffold/{path}` is written once when it is missing
 * and belongs to the project from then on, so it is never overwritten.
 *
 * `{kit}/features/{name}/` is a feature a project chooses to install, such as the image library.
 * Its `files/` and `scaffold/` mean the same as the kit's own. Once installed, the feature is
 * recorded in the project's `.kit/features.json`, so every later install keeps it up to date and
 * the checks hold its files to the kit's copy. Its `feature.json` lists what the project wires by
 * hand once (`by_hand`), and its `lang/en.json` the words its pages and messages speak: every key
 * a project's `lang/*.json` lacks is added in English, for the project to translate.
 *
 * A migration is matched by its name without the timestamp, so a project that already runs the
 * kit's table under another timestamp never gets a second migration for it.
 */
final class KitInstaller
{
    private const string MIGRATIONS = 'database/migrations/';

    /**
     * Where a project records the features it installed, from its root.
     */
    public const string FEATURES_FILE = '.kit/features.json';

    public function __construct(private readonly string $kitPath, private readonly string $projectPath) {}

    /**
     * Writes the kit's files, those of every feature the project installed before, and those of
     * the features $with names, which are recorded as installed from then on.
     *
     * @param  list<string>  $with
     * @return array{written: list<string>, overwritten: list<string>, unchanged: list<string>, differs: list<string>, kept: list<string>, enabled: list<string>, translated: array<string, int>, feature_written: list<string>}
     *
     * @throws InvalidArgumentException when $with names a feature the kit does not ship
     */
    public function install(bool $force = false, array $with = []): array
    {
        $shipped = $this->features();
        $unknown = array_values(array_diff($with, $shipped));

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'The kit has no feature [%s]. It ships: %s.',
                implode(', ', $unknown),
                $shipped === [] ? 'none' : implode(', ', $shipped),
            ));
        }

        $before = $this->enabledFeatures();
        $enabled = array_values(array_diff(array_unique($with), $before));

        if ($enabled !== []) {
            $this->recordFeatures([...$before, ...$enabled]);
        }

        $report = ['written' => [], 'overwritten' => [], 'unchanged' => [], 'differs' => [], 'kept' => [], 'enabled' => $enabled, 'translated' => [], 'feature_written' => []];

        foreach ($this->folders('files') as $folder) {
            $this->installFiles($folder, $force, $report);
        }

        foreach ($this->folders('scaffold') as $folder) {
            $this->installScaffold($folder, $report);
        }

        foreach ($this->enabledFeatures() as $name) {
            $this->addWords($name, $report);
        }

        return $report;
    }

    /**
     * Every feature the kit ships, by name, in name order.
     *
     * @return list<string>
     */
    public function features(): array
    {
        $names = array_map(basename(...), glob($this->kitPath.'/features/*', GLOB_ONLYDIR) ?: []);
        sort($names);

        return $names;
    }

    /**
     * The features the project installed, as its `.kit/features.json` records them, in name
     * order. A name the kit no longer ships is left out.
     *
     * @return list<string>
     */
    public function enabledFeatures(): array
    {
        $path = $this->projectPath.'/'.self::FEATURES_FILE;
        $names = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($names)) {
            return [];
        }

        return array_values(array_intersect($this->features(), array_filter($names, is_string(...))));
    }

    /**
     * What feature $name asks the project to wire by hand once, from its `feature.json`.
     *
     * @return list<string>
     */
    public function byHandOf(string $name): array
    {
        $path = $this->kitPath.'/features/'.$name.'/feature.json';
        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $lines = is_array($manifest) ? ($manifest['by_hand'] ?? null) : null;

        return is_array($lines) ? array_values(array_filter($lines, is_string(...))) : [];
    }

    /**
     * Where each of the kit's own files (`{kit}/files/`, and `files/` of every feature the
     * project installed) lands in the project, from the project root: the files the `kit-files`
     * checks hold to the kit's copy.
     *
     * @return list<string>
     */
    public function ownedPaths(): array
    {
        $paths = [];

        foreach ($this->folders('files') as $folder) {
            foreach (array_keys($this->filesIn($folder)) as $relative) {
                $paths[] = substr($this->targetOf($relative), strlen($this->projectPath) + 1);
            }
        }

        return $paths;
    }

    /**
     * @param  array{written: list<string>, overwritten: list<string>, unchanged: list<string>, differs: list<string>, kept: list<string>, enabled: list<string>, translated: array<string, int>, feature_written: list<string>}  $report
     */
    private function installFiles(string $folder, bool $force, array &$report): void
    {
        foreach ($this->filesIn($folder) as $relative => $source) {
            $target = $this->targetOf($relative);
            $shown = substr($target, strlen($this->projectPath) + 1);

            if (! is_file($target)) {
                $this->write($source, $target);
                $this->reportWritten($folder, $shown, $report);
            } elseif (file_get_contents($target) === file_get_contents($source)) {
                $report['unchanged'][] = $shown;
            } elseif ($force) {
                $this->write($source, $target);
                $report['overwritten'][] = $shown;
            } else {
                $report['differs'][] = $shown;
            }
        }
    }

    /**
     * @param  array{written: list<string>, overwritten: list<string>, unchanged: list<string>, differs: list<string>, kept: list<string>, enabled: list<string>, translated: array<string, int>, feature_written: list<string>}  $report
     */
    private function installScaffold(string $folder, array &$report): void
    {
        foreach ($this->filesIn($folder) as $relative => $source) {
            $target = $this->targetOf($relative);
            $shown = substr($target, strlen($this->projectPath) + 1);

            if (is_file($target)) {
                $report['kept'][] = $shown;
            } else {
                $this->write($source, $target);
                $this->reportWritten($folder, $shown, $report);
            }
        }
    }

    /**
     * Counts a file as written, and as written by a feature when it came from one, so the command
     * names the kit's own wiring only when a file of the kit's own was new.
     *
     * @param  array{written: list<string>, overwritten: list<string>, unchanged: list<string>, differs: list<string>, kept: list<string>, enabled: list<string>, translated: array<string, int>, feature_written: list<string>}  $report
     */
    private function reportWritten(string $folder, string $shown, array &$report): void
    {
        $report['written'][] = $shown;

        if (str_starts_with($folder, 'features/')) {
            $report['feature_written'][] = $shown;
        }
    }

    /**
     * Adds every key of feature $name's `lang/en.json` that a project's `lang/*.json` lacks, in
     * English, and counts them per file. A project with no locale file yet gets `lang/en.json`.
     *
     * @param  array{written: list<string>, overwritten: list<string>, unchanged: list<string>, differs: list<string>, kept: list<string>, enabled: list<string>, translated: array<string, int>, feature_written: list<string>}  $report
     */
    private function addWords(string $name, array &$report): void
    {
        $source = $this->kitPath.'/features/'.$name.'/lang/en.json';
        $words = is_file($source) ? json_decode((string) file_get_contents($source), true) : null;

        if (! is_array($words) || $words === []) {
            return;
        }

        $files = glob($this->projectPath.'/lang/*.json') ?: [$this->projectPath.'/lang/en.json'];

        foreach ($files as $path) {
            $current = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];

            if (! is_array($current)) {
                continue;
            }

            $merged = $current + $words;
            $added = count($merged) - count($current);

            if ($added === 0) {
                continue;
            }

            ksort($merged);

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }

            file_put_contents($path, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $shown = substr($path, strlen($this->projectPath) + 1);
            $report['translated'][$shown] = ($report['translated'][$shown] ?? 0) + $added;
        }
    }

    /**
     * The kit's folder of one kind (`files` or `scaffold`), then that folder of each feature the
     * project installed, as paths from the kit's root.
     *
     * @return list<string>
     */
    private function folders(string $kind): array
    {
        return [$kind, ...array_map(fn (string $name): string => "features/{$name}/{$kind}", $this->enabledFeatures())];
    }

    /**
     * @param  list<string>  $names
     */
    private function recordFeatures(array $names): void
    {
        $names = array_values(array_unique($names));
        sort($names);
        $path = $this->projectPath.'/'.self::FEATURES_FILE;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, json_encode($names, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * Every file under one of the kit's folders, by its path from the project root, in path order.
     *
     * @return array<string, string> project-relative path => the kit's file
     */
    private function filesIn(string $folder): array
    {
        $root = $this->kitPath.'/'.$folder;

        if (! is_dir($root)) {
            return [];
        }

        $files = [];

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
     * Where a kit file lands in the project: its own path, or for a migration the project's
     * migration of the same name under any timestamp, when there is one.
     */
    private function targetOf(string $relative): string
    {
        if (str_starts_with($relative, self::MIGRATIONS)
            && preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_(.+)$/', basename($relative), $name) === 1) {
            $existing = glob($this->projectPath.'/'.self::MIGRATIONS.'*_'.$name[1]) ?: [];

            if ($existing !== []) {
                return $existing[0];
            }
        }

        return $this->projectPath.'/'.$relative;
    }

    private function write(string $source, string $target): void
    {
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        copy($source, $target);
    }
}
