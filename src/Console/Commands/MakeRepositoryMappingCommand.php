<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\EditsEntityClass;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureRepositoryMapping;

/**
 * Writes the mapping of a repository `make:eloquent-repository` wrote (repositories.md), from the
 * aggregate's design in its context manifest (structure.md, "Tables"): `toModel()` and `toEntity()`
 * for the root's state and its table, and for an aggregate with children, `newQuery()` eager loading
 * each child table, a `write()` that calls `syncChildren()` for each, and a private pair of mapping
 * helpers per child.
 *
 * It refuses while the repository, the models or the entities' state are not built yet, and while
 * a property or a column does not map plainly (StructureRepositoryMapping): that mapping is written
 * by hand. It writes only over the todos the repository starts with, so a mapping already written
 * is never touched.
 */
#[Signature('make:repository-mapping {aggregate : The aggregate whose repository to map} {--domain= : Context/Aggregate that owns it}')]
#[Description('Write the toModel()/toEntity() mapping of an aggregate\'s repository, and syncChildren() for its child tables, from the structure manifest')]
class MakeRepositoryMappingCommand extends Command
{
    use EditsEntityClass;

    public function handle(): int
    {
        $aggregate = (string) $this->argument('aggregate');
        $segments = explode('/', (string) $this->option('domain'));

        if (count($segments) !== 2 || $segments[1] !== $aggregate) {
            $this->components->error("Pass --domain={Context}/{$aggregate}.");

            return self::FAILURE;
        }

        $context = $segments[0];
        $files = new StructureFiles(base_path());
        $manifest = $files->read($context);

        if (! is_array($manifest) || $files->problems($context, $manifest) !== [] || ! StructureRepositoryMapping::designed($manifest, $aggregate)) {
            $this->components->error("No valid {$context} manifest designs a table for {$aggregate} and its repository.");

            return self::FAILURE;
        }

        $mapping = StructureRepositoryMapping::of(base_path(), $context, $aggregate, $manifest, new StructureReader(base_path()));

        if ($mapping['problems'] !== []) {
            $this->components->error("The mapping of Eloquent{$aggregate}Repository waits:");
            $this->components->bulletList($mapping['problems']);

            return self::FAILURE;
        }

        $code = File::get($mapping['repository']);

        if (StructureRepositoryMapping::mapped($code)) {
            $this->components->error("The mapping of Eloquent{$aggregate}Repository is written already: change it by hand.");

            return self::FAILURE;
        }

        $written = $this->written($code, $mapping);

        if ($written === null) {
            $this->components->error("Eloquent{$aggregate}Repository is not laid out the way make:eloquent-repository writes it: write the mapping by hand.");

            return self::FAILURE;
        }

        File::put($mapping['repository'], $written);
        $this->components->info(sprintf('The mapping of [%s] written successfully.', str_replace(base_path().'/', '', $mapping['repository'])));

        return self::SUCCESS;
    }

    /**
     * The repository with its mapping written over the todos, or null when it does not hold them
     * the way the stub writes them.
     *
     * @param  array{model: string, entity: string, toModel: list<string>, toEntity: list<string>, children: list<array{name: string, entity: string, model: string, relation: string, property: string, variable: string, foreignKey: string, parentId: string|null, toModel: list<string>, toEntity: list<string>}>, imports: list<string>}  $mapping
     */
    private function written(string $code, array $mapping): ?string
    {
        $toModel = '        '.StructureRepositoryMapping::TO_MODEL_TODO."\n";
        $toEntity = '            '.StructureRepositoryMapping::TO_ENTITY_TODO."\n";
        $query = "        return {$mapping['model']}::query();\n";
        $toModelMethod = "    #[Override]\n    protected function toModel(";
        $children = $mapping['children'];

        if (substr_count($code, $toModel) !== 1 || substr_count($code, $toEntity) !== 1 || ($children !== [] && (substr_count($code, $query) !== 1 || substr_count($code, $toModelMethod) !== 1))) {
            return null;
        }

        $lines = fn (array $lines, int $indent): string => implode('', array_map(fn (string $line): string => str_repeat(' ', $indent).$line."\n", $lines));
        $code = str_replace($toModel, $lines($mapping['toModel'], 8), $code);
        $code = str_replace($toEntity, $lines($mapping['toEntity'], 12), $code);

        if ($children !== []) {
            $relations = array_map(fn (array $child): string => "'{$child['relation']}'", $children);
            $with = count($relations) === 1 ? $relations[0] : '['.implode(', ', $relations).']';
            $code = str_replace($query, "        return {$mapping['model']}::query()->with({$with});\n", $code);
            $code = str_replace($toModelMethod, $this->writeMethod($mapping['entity'], $children)."\n".$toModelMethod, $code);
            $code = (string) preg_replace('/\n}\n?$/', "\n".implode('', array_map($this->helpers(...), $children))."}\n", $code);
        }

        return $this->withImports($code, array_combine(array_map(class_basename(...), $mapping['imports']), $mapping['imports']));
    }

    /**
     * The `write()` of an aggregate with children: the roots, then each child table through
     * `syncChildren()`, which deletes the children that are gone before it writes the rest.
     *
     * @param  list<array{name: string, entity: string, model: string, relation: string, property: string, variable: string, foreignKey: string, parentId: string|null, toModel: list<string>, toEntity: list<string>}>  $children
     */
    private function writeMethod(string $entity, array $children): string
    {
        $lists = array_map(fn (array $child): string => "        \${$child['property']} = [];\n", $children);
        $loops = array_map(fn (array $child): string => sprintf(
            "            foreach (\$entity->%s() as \$%s) {\n                \$%s[] = \$this->to%sModel(\$%s%s);\n            }\n",
            $child['property'],
            $child['variable'],
            $child['property'],
            $child['name'],
            $child['variable'],
            $child['parentId'] === null ? '' : ', $entity->id()',
        ), $children);
        $syncs = array_map(fn (array $child): string => "        \$this->syncChildren({$child['model']}::class, '{$child['foreignKey']}', \$ids, \${$child['property']}, \$mode);\n", $children);

        return "    #[Override]\n"
            ."    protected function write(array \$entities, WriteMode \$mode): void\n"
            ."    {\n"
            ."        \$this->writeRoots(\$entities, \$mode);\n\n"
            ."        \$ids = array_map(fn ({$entity} \$entity): string => \$entity->id(), \$entities);\n"
            .implode('', $lists)
            ."\n        foreach (\$entities as \$entity) {\n"
            .implode("\n", $loops)
            ."        }\n\n"
            .implode('', $syncs)
            ."    }\n";
    }

    /**
     * The private pair that maps one child to its row and back.
     *
     * @param  array{name: string, entity: string, model: string, relation: string, property: string, variable: string, foreignKey: string, parentId: string|null, toModel: list<string>, toEntity: list<string>}  $child
     */
    private function helpers(array $child): string
    {
        $parent = $child['parentId'] === null ? '' : ", string \${$child['parentId']}";

        return "\n    private function to{$child['name']}Model({$child['entity']} \${$child['variable']}{$parent}): {$child['model']}\n"
            ."    {\n"
            ."        \$model = new {$child['model']};\n"
            ."        \$model->id = \${$child['variable']}->id();\n"
            .implode('', array_map(fn (string $line): string => "        {$line}\n", $child['toModel']))
            ."\n        return \$model;\n"
            ."    }\n\n"
            ."    private function to{$child['name']}Entity({$child['model']} \$model): {$child['entity']}\n"
            ."    {\n"
            ."        return {$child['entity']}::reconstitute(\n"
            ."            id: \$model->id,\n"
            .implode('', array_map(fn (string $line): string => "            {$line}\n", $child['toEntity']))
            ."        );\n"
            ."    }\n";
    }
}
