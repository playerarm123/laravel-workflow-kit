<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

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
 * A migration is matched by its name without the timestamp, so a project that already runs the
 * kit's table under another timestamp never gets a second migration for it.
 */
final class KitInstaller
{
    private const string MIGRATIONS = 'database/migrations/';

    public function __construct(private readonly string $kitPath, private readonly string $projectPath) {}

    /**
     * @return array{written: list<string>, overwritten: list<string>, unchanged: list<string>, differs: list<string>, kept: list<string>}
     */
    public function install(bool $force = false): array
    {
        $report = ['written' => [], 'overwritten' => [], 'unchanged' => [], 'differs' => [], 'kept' => []];

        foreach ($this->filesIn('files') as $relative => $source) {
            $target = $this->targetOf($relative);
            $shown = substr($target, strlen($this->projectPath) + 1);

            if (! is_file($target)) {
                $this->write($source, $target);
                $report['written'][] = $shown;
            } elseif (file_get_contents($target) === file_get_contents($source)) {
                $report['unchanged'][] = $shown;
            } elseif ($force) {
                $this->write($source, $target);
                $report['overwritten'][] = $shown;
            } else {
                $report['differs'][] = $shown;
            }
        }

        foreach ($this->filesIn('scaffold') as $relative => $source) {
            $target = $this->targetOf($relative);
            $shown = substr($target, strlen($this->projectPath) + 1);

            if (is_file($target)) {
                $report['kept'][] = $shown;
            } else {
                $this->write($source, $target);
                $report['written'][] = $shown;
            }
        }

        return $report;
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
