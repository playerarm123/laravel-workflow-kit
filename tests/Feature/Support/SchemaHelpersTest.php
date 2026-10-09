<?php

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once dirname(__DIR__, 2).'/Architecture/Support/rules.php';

/**
 * The schema helpers the kit's project tests share. The migrations' schema checks read only the
 * database the app works in. A database user that
 * sees other schemas (another database on a MySQL server, another schema on Postgres) must not
 * hand their tables to the checks.
 */
describe('ruleSchemaTables', function () {
    it('lists the tables of the connection\'s own schema and none of another one', function () {
        DB::statement('create schema if not exists sampling_schema_tables');
        DB::statement('create table if not exists sampling_schema_tables.sampling_other (id bigint primary key)');

        try {
            expect(collect(Schema::getTables())->pluck('name'))->toContain('sampling_other')
                ->and(ruleSchemaTables())->not->toContain('sampling_other')
                ->and(ruleSchemaTables())->toContain('migrations');
        } finally {
            DB::statement('drop schema if exists sampling_schema_tables cascade');
        }
    });
});

describe('ruleForgetSchemaAfterDdl', function () {
    it('sends the next test through migrate:fresh on an engine whose DDL outlives the transaction, and only there', function (string $driver, bool $migrated) {
        $before = RefreshDatabaseState::$migrated;
        RefreshDatabaseState::$migrated = true;

        try {
            ruleForgetSchemaAfterDdl($driver);

            expect(RefreshDatabaseState::$migrated)->toBe($migrated);
        } finally {
            RefreshDatabaseState::$migrated = $before;
        }
    })->with([
        'mysql' => ['mysql', false],
        'mariadb' => ['mariadb', false],
        'pgsql' => ['pgsql', true],
    ]);
});
