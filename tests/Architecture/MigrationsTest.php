<?php

require_once __DIR__.'/Support/rules.php';

/**
 * The machine-checked half of migrations.md that reads the source — change
 * the two together. The half that reads the migrated schema (primary keys, foreign key
 * indexes, rollback) needs a database, so it lives in tests/Feature/Database/MigrationsTest.php.
 *
 * `framework_migrations` are globs of the files Laravel and its packages publish. They keep
 * their own idioms, so only the schema half checks them.
 *
 * `create_stub` is the stub `make:migration --create` copies; it must open every table on its
 * uuid key.
 *
 * @return array{
 *     kit_files: list<string>,
 *     create_stub: string,
 *     path: string,
 *     framework_migrations: list<string>,
 *     on_delete: list<string>,
 *     foreign_key: array<string, string>,
 *     money: array<string, string>,
 *     json: array<string, string>,
 *     enum: array<string, string>,
 *     schema_only: array<string, string>,
 * }
 */
function migrationsSpec(): array
{
    return [
        'kit_files' => [
            'stubs/migration.create.stub',
            'stubs/migration.update.stub',
            'stubs/migration.stub',
            'tests/Feature/Database/MigrationsTest.php',
        ],
        'create_stub' => 'stubs/migration.create.stub',
        'path' => 'database/migrations',
        'framework_migrations' => [
            '0001_01_01_*.php',
            '*_create_passkeys_table.php',
            '*_add_two_factor_columns_to_users_table.php',
        ],
        'on_delete' => ['cascadeOnDelete', 'restrictOnDelete', 'nullOnDelete'],
        'foreign_key' => [
            '/->foreign(Id|Ulid)\s*\(/' => 'keys on a bigint or a ulid — every id is a uuid, use foreignUuid()',
            '/->foreign(Id|Uuid|Ulid)For\s*\(/' => 'names a model — use foreignUuid(\'{x}_id\'), a migration never loads App\\ classes',
            '/->constrained\s*\(\s*\)/' => 'leaves the parent table to inference — name it in constrained(\'{table}\')',
            '/->(nullable)?(uuid|ulid)?[mM]orphs\s*\(/' => 'stores a class name as the type — keep a uuid id beside a string type that holds a domain enum value',
            '/->references\s*\(|->onDelete\s*\(/' => 'uses the longhand — use foreignUuid()->constrained(\'{table}\') and cascadeOnDelete()/restrictOnDelete()/nullOnDelete()',
        ],
        'money' => [
            '/->(float|double|unsignedFloat|unsignedDouble)\s*\(/' => 'stores a number as floating point — store money in minor units with unsignedBigInteger()',
        ],
        'json' => [
            '/->json\s*\(/' => 'uses json — use jsonb(), which Laravel writes as json on mysql/mariadb',
        ],
        'enum' => [
            '/->enum\s*\(/' => 'uses a database enum — store the backed enum\'s value in string(\'{x}\', {length})',
        ],
        'schema_only' => [
            '/(?<![\w\\\\])\\\\?App\\\\/' => 'reaches into App\\ — a migration is history and must not change when the app does',
            '/\bStr::/' => 'mints or shapes data — a migration changes the schema only',
            '/\bDB::(?!statement\b)\w+/' => 'reads or writes rows — backfill through a command that calls a handler',
        ],
    ];
}

/**
 * The migrations this rule's source checks read: every one but those the framework publishes.
 *
 * @return list<string>
 */
function migrationsFiles(): array
{
    return array_values(array_filter(
        ruleSourceFiles(migrationsSpec()['path'], ['php']),
        function (string $file): bool {
            foreach (migrationsSpec()['framework_migrations'] as $glob) {
                if (fnmatch($glob, basename($file))) {
                    return false;
                }
            }

            return true;
        },
    ));
}

/**
 * Every line that matches one of a check's patterns, with that pattern's message.
 *
 * @param  array<string, string>  $patterns
 * @return list<array{subject: string, message: string}>
 */
function migrationsMatches(array $patterns): array
{
    $violations = [];

    foreach ($patterns as $pattern => $message) {
        $violations = [...$violations, ...ruleCodeMatches(migrationsFiles(), $pattern, $message)];
    }

    return $violations;
}

/**
 * The body of a method in a migration's code, or null when the method is missing.
 */
function migrationsMethodBody(string $code, string $method): ?string
{
    if (preg_match('/function\s+'.$method.'\s*\(\s*\)\s*:\s*void\s*\{/', $code, $match, PREG_OFFSET_CAPTURE) !== 1) {
        return null;
    }

    $start = $match[0][1] + strlen($match[0][0]);
    $depth = 1;

    for ($i = $start; $i < strlen($code); $i++) {
        $depth += match ($code[$i]) {
            '{' => 1,
            '}' => -1,
            default => 0,
        };

        if ($depth === 0) {
            return substr($code, $start, $i - $start);
        }
    }

    return null;
}

describe('migrations', function () {
    it('ships the kit files', function () {
        $violations = ruleKitFileViolations(migrationsSpec()['kit_files']);

        $stub = (string) @file_get_contents(ruleProjectPath(migrationsSpec()['create_stub']));

        if ($stub !== '' && preg_match('/->uuid\(\s*\'id\'\s*\)\s*->primary\(\)/', $stub) !== 1) {
            $violations[] = ['subject' => migrationsSpec()['create_stub'], 'message' => "must open the table with \$table->uuid('id')->primary()"];
        }

        expect(ruleUnexcused('migrations', 'kit-files', $violations))->toBe([]);
    });

    it('writes every migration as an anonymous class that can be reversed', function () {
        $violations = [];

        foreach (migrationsFiles() as $file) {
            $code = ruleCodeWithoutComments($file);

            if (preg_match('/return\s+new\s+class\s+extends\s+Migration\b/', $code) !== 1) {
                $violations[] = ['subject' => $file, 'message' => 'must return new class extends Migration'];
            }

            if (trim((string) migrationsMethodBody($code, 'down')) === '') {
                $violations[] = ['subject' => $file, 'message' => 'has no down(), or an empty one — undo what up() does'];
            }

            preg_match_all('/Schema::create\s*\(\s*[\'"]([^\'"]+)[\'"]/', $code, $created);

            foreach ($created[1] as $table) {
                if (preg_match('/Schema::dropIfExists\s*\(\s*[\'"]'.preg_quote($table, '/').'[\'"]/', $code) !== 1) {
                    $violations[] = ['subject' => $file, 'message' => sprintf("creates %s but down() never calls Schema::dropIfExists('%s')", $table, $table)];
                }
            }
        }

        expect(ruleUnexcused('migrations', 'shape', $violations))->toBe([]);
    });

    it('declares every foreign key on a uuid, naming its parent and what a delete does', function () {
        $violations = migrationsMatches(migrationsSpec()['foreign_key']);
        $onDelete = '/->('.implode('|', migrationsSpec()['on_delete']).')\s*\(/';

        foreach (migrationsFiles() as $file) {
            foreach (explode(';', ruleCodeWithoutComments($file)) as $statement) {
                if (preg_match('/->constrained\s*\(/', $statement) === 1 && preg_match($onDelete, $statement) !== 1) {
                    $violations[] = [
                        'subject' => $file,
                        'message' => sprintf(
                            'leaves what a delete does to the database: `%s` — add %s()',
                            trim((string) preg_replace('/\s+/', ' ', $statement)),
                            implode('(), ', migrationsSpec()['on_delete']),
                        ),
                    ];
                }
            }
        }

        expect(ruleUnexcused('migrations', 'foreign-key', $violations))->toBe([]);
    });

    it('never stores a number as floating point', function () {
        expect(ruleUnexcused('migrations', 'money', migrationsMatches(migrationsSpec()['money'])))->toBe([]);
    });

    it('stores json as jsonb', function () {
        expect(ruleUnexcused('migrations', 'json', migrationsMatches(migrationsSpec()['json'])))->toBe([]);
    });

    it('stores an enum as a string', function () {
        expect(ruleUnexcused('migrations', 'enum', migrationsMatches(migrationsSpec()['enum'])))->toBe([]);
    });

    it('changes the schema and nothing else', function () {
        expect(ruleUnexcused('migrations', 'schema-only', migrationsMatches(migrationsSpec()['schema_only'])))->toBe([]);
    });
});
