<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureTables;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureTableWriter;

/**
 * Writes the migration of a table a context manifest designs (structure.md, "Tables"), in the
 * shape migrations.md gives it: the one that creates it, or, once the migrations build it, a new
 * one that adds the columns, foreign keys and indexes they lack, never an edit in place. What the
 * design describes another way than the migrations built it is left to a person, and the check
 * names it.
 *
 * It refuses while a table a foreign key points at is not built yet, so the migrations run in the
 * order they depend on each other. Its date comes after every migration already there.
 */
#[Signature('make:table-migration {table : A table a context manifest designs} {--path= : The folder the migrations live in, when it is not database/migrations}')]
#[Description('Write the migration that builds a table the structure manifest designs, or adds what the built table lacks')]
class MakeTableMigrationCommand extends Command
{
    public function handle(): int
    {
        $name = (string) $this->argument('table');
        $designed = (new StructureFiles(base_path()))->designedTables()[$name] ?? null;

        if ($designed === null) {
            $this->components->error(sprintf('No valid context manifest designs the table %s.', $name));

            return self::FAILURE;
        }

        $path = $this->option('path');
        $folder = is_string($path) && $path !== '' ? (str_starts_with($path, '/') ? $path : base_path($path)) : base_path(StructureTables::MIGRATIONS);
        $tables = new StructureTables(base_path(), $folder);
        $schema = $tables->schema();

        foreach ($designed['table']['foreignKeys'] ?? [] as $key) {
            $parent = is_array($key) && is_string($key['table'] ?? null) ? $key['table'] : null;

            if ($parent !== null && $parent !== $name && ! isset($schema[$parent]) && ! in_array($parent, $tables->frameworkTables(), true)) {
                $this->components->error(sprintf('The table %s, which %s points at, is not built yet: write its migration first.', $parent, $name));

                return self::FAILURE;
            }
        }

        $built = $schema[$name] ?? null;
        $additions = StructureTableWriter::additions($designed['table'], $built);

        if (StructureTableWriter::addsNothing($additions)) {
            $this->components->info(sprintf('The migrations already build everything %s designs.', $name));

            return self::SUCCESS;
        }

        File::ensureDirectoryExists($folder);
        $file = $folder.'/'.$this->datePrefix($folder).'_'.StructureTableWriter::migrationName($name, $additions, $built === null).'.php';
        File::put($file, StructureTableWriter::migration($name, $additions, $built === null));

        $this->components->info(sprintf('Migration [%s] created successfully.', str_starts_with($file, base_path().'/') ? substr($file, strlen(base_path()) + 1) : $file));

        return self::SUCCESS;
    }

    /**
     * Now, or a second after the latest migration in the folder when that is later, so a migration
     * written in the same second as the one it depends on still runs after it.
     */
    private function datePrefix(string $folder): string
    {
        $now = CarbonImmutable::now()->startOfSecond();
        $latest = null;

        foreach (glob($folder.'/*.php') ?: [] as $file) {
            if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', basename($file), $match) === 1 && ($latest === null || $match[1] > $latest)) {
                $latest = $match[1];
            }
        }

        $after = $latest === null ? null : CarbonImmutable::createFromFormat('Y_m_d_His', $latest);

        return ($after !== null && $after >= $now ? $after->addSecond() : $now)->format('Y_m_d_His');
    }
}
