<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureTableWriter;

/**
 * Writes the model a table the context manifest designs names, with its factory (structure.md,
 * "Tables"), in the shape models.md gives them: what `make:model --factory` writes, plus
 * `#[Fillable]`, a `@property` per column, `casts()`, the relations, and a factory `definition()`
 * that fills every column.
 *
 * It refuses while an enum a column names is not built yet, because the cast and the factory name
 * its class. A factory already there is kept.
 */
#[Signature('make:table-model {table : A table a context manifest designs, which names its model} {--force : Overwrite the model and the factory if they already exist}')]
#[Description('Write the model and factory of a table the structure manifest designs')]
class MakeTableModelCommand extends Command
{
    public function handle(): int
    {
        $name = (string) $this->argument('table');
        $designedTables = (new StructureFiles(base_path()))->designedTables();
        $designed = $designedTables[$name] ?? null;

        if ($designed === null) {
            $this->components->error(sprintf('No valid context manifest designs the table %s.', $name));

            return self::FAILURE;
        }

        $model = $designed['table']['model'] ?? null;

        if (! is_string($model)) {
            $this->components->error(sprintf('The table %s names no model.', $name));

            return self::FAILURE;
        }

        $related = StructureTableWriter::related($name, $designedTables, new StructureReader(base_path()));

        if ($related['missing'] !== []) {
            $this->components->error(sprintf('%s %s not built yet: build the enums first.', implode(', ', $related['missing']), count($related['missing']) === 1 ? 'is' : 'are'));

            return self::FAILURE;
        }

        $path = app_path("Models/{$model}.php");
        $force = (bool) $this->option('force');

        if (is_file($path) && ! $force) {
            $this->components->error(sprintf('Model [%s] already exists.', $model));

            return self::FAILURE;
        }

        $design = StructureTableWriter::additions($designed['table'], null);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, StructureTableWriter::model($model, $name, $design, $related));
        $this->components->info(sprintf('Model [app/Models/%s.php] created successfully.', $model));

        $factory = database_path("factories/{$model}Factory.php");

        if (is_file($factory) && ! $force) {
            $this->components->warn(sprintf('Factory [database/factories/%sFactory.php] already exists, so it is kept.', $model));

            return self::SUCCESS;
        }

        File::ensureDirectoryExists(dirname($factory));
        File::put($factory, StructureTableWriter::factory($model, $design, $related));
        $this->components->info(sprintf('Factory [database/factories/%sFactory.php] created successfully.', $model));

        return self::SUCCESS;
    }
}
