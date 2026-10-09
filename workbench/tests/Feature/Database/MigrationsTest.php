<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../../../vendor/playerarm123/laravel-workflow-kit/tests/Architecture/Support/rules.php';

/**
 * The machine-checked half of migrations.md that reads the migrated schema —
 * change the two together. The source half is the workflow kit's
 * tests/Architecture/MigrationsTest.php.
 *
 * `framework_tables` are the tables Laravel and its packages key their own way (a string
 * session id, a bigint job id); every other table is keyed on a uuid `id`. `uuid_types` are
 * the column types a uuid becomes on each engine.
 *
 * @return array{framework_tables: list<string>, uuid_types: list<string>}
 */
function migrationSchemaSpec(): array
{
    return [
        'framework_tables' => [
            'migrations',
            'password_reset_tokens',
            'sessions',
            'cache',
            'cache_locks',
            'jobs',
            'job_batches',
            'failed_jobs',
            'passkeys',
        ],
        'uuid_types' => ['uuid', 'char(36)'],
    ];
}

/**
 * @return list<string>
 */
function migrationSchemaTables(): array
{
    return ruleSchemaTables();
}

describe('the migrated schema', function () {
    it('keys every table on a uuid id', function () {
        $violations = [];

        foreach (array_diff(migrationSchemaTables(), migrationSchemaSpec()['framework_tables']) as $table) {
            $primary = collect(Schema::getIndexes($table))->firstWhere('primary', true);
            $id = collect(Schema::getColumns($table))->firstWhere('name', 'id');

            if ($primary === null || $primary['columns'] !== ['id']) {
                $violations[] = ['subject' => $table, 'message' => 'is not keyed on id alone — use $table->uuid(\'id\')->primary()'];
            } elseif (! in_array($id['type_name'] ?? null, migrationSchemaSpec()['uuid_types'], true)
                && ! in_array($id['type'] ?? null, migrationSchemaSpec()['uuid_types'], true)) {
                $violations[] = ['subject' => $table, 'message' => sprintf('keys on a %s id — use $table->uuid(\'id\')->primary()', $id['type'] ?? '?')];
            }
        }

        expect(ruleUnexcused('migrations', 'primary-key', $violations))->toBe([]);
    });

    it('indexes every foreign key, which postgres never does by itself', function () {
        $violations = [];

        foreach (migrationSchemaTables() as $table) {
            $indexes = array_column(Schema::getIndexes($table), 'columns');

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $columns = $foreignKey['columns'];
                $covered = array_filter($indexes, fn (array $index): bool => array_slice($index, 0, count($columns)) === $columns);

                if ($covered === []) {
                    $violations[] = [
                        'subject' => $table.'.'.implode(',', $columns),
                        'message' => 'references '.$foreignKey['foreign_table'].' but leads no index — add ->index(), or put it first in a composite index',
                    ];
                }
            }
        }

        expect(ruleUnexcused('migrations', 'foreign-key-index', $violations))->toBe([]);
    });

    it('rolls every migration back to an empty database and up again', function () {
        expect(Artisan::call('migrate:reset', ['--force' => true]))->toBe(0);

        $left = array_values(array_diff(migrationSchemaTables(), ['migrations']));
        $violations = array_map(
            fn (string $table): array => ['subject' => $table, 'message' => 'is still there after migrate:reset — the down() that undoes its up() misses it'],
            $left,
        );

        expect(ruleUnexcused('migrations', 'rollback', $violations))->toBe([])
            ->and(Artisan::call('migrate', ['--force' => true]))->toBe(0);
    });
});
