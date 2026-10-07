<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../../vendor/playerarm123/laravel-workflow-kit/tests/Architecture/Support/rules.php';

/**
 * The machine-checked half of models.md that compares each model with the
 * table it maps — change the two together. The source half is the workflow kit's
 * tests/Architecture/ModelsTest.php.
 *
 * `cast_types` maps a built-in cast to the type its `@property` must name; a cast that is a
 * class (an enum, a value object cast) must name that class instead. A cast missing from the
 * map (a custom `CastsAttributes`) is left to review. `date_type` is the type of a column
 * Eloquent treats as a date without a cast (`created_at`, `updated_at`).
 *
 * @return array{models_path: string, cast_types: array<string, string>, date_type: string}
 */
function modelSchemaSpec(): array
{
    return [
        'models_path' => 'app/Models',
        'cast_types' => [
            'bool' => 'bool',
            'boolean' => 'bool',
            'int' => 'int',
            'integer' => 'int',
            'float' => 'float',
            'double' => 'float',
            'real' => 'float',
            'string' => 'string',
            'hashed' => 'string',
            'encrypted' => 'string',
            'array' => 'array',
            'json' => 'array',
            'date' => 'Carbon',
            'datetime' => 'Carbon',
            'immutable_date' => 'CarbonImmutable',
            'immutable_datetime' => 'CarbonImmutable',
        ],
        'date_type' => 'Carbon',
    ];
}

/**
 * Every concrete model under the models path, built empty.
 *
 * @return array<class-string<Model>, Model>
 */
function modelSchemaModels(): array
{
    $models = [];

    foreach (ruleSourceFiles(modelSchemaSpec()['models_path'], ['php']) as $file) {
        $class = ruleClassOf($file);

        if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $models[$class] = new $class;
        }
    }

    return $models;
}

/**
 * The `@property` lines of a model's class docblock, as column => type. A `@property-read`
 * line (a relation Larastan already reads from its method) is not a column and is skipped.
 *
 * @param  class-string<Model>  $class
 * @return array<string, string>
 */
function modelSchemaProperties(string $class): array
{
    preg_match_all('/^\s*\*\s*@property\s+(.+?)\s+\$(\w+)/m', (string) (new ReflectionClass($class))->getDocComment(), $matches, PREG_SET_ORDER);

    $properties = [];

    foreach ($matches as [, $type, $name]) {
        $properties[$name] = $type;
    }

    return $properties;
}

/**
 * The type a cast must show in `@property`, or null when only a reviewer can tell.
 */
function modelSchemaExpectedType(string $cast): ?string
{
    $cast = explode(':', $cast, 2)[0];

    if (isset(modelSchemaSpec()['cast_types'][$cast])) {
        return modelSchemaSpec()['cast_types'][$cast];
    }

    return str_contains($cast, '\\') && class_exists($cast) ? class_basename($cast) : null;
}

describe('the models against the migrated schema', function () {
    it('gives every column one @property line, and nothing else', function () {
        $violations = [];

        foreach (modelSchemaModels() as $class => $model) {
            $columns = Schema::getColumnListing($model->getTable());
            $properties = array_keys(modelSchemaProperties($class));

            foreach (array_diff($columns, $properties) as $missing) {
                $violations[] = ['subject' => $class, 'message' => sprintf('has no @property for the column %s.%s', $model->getTable(), $missing)];
            }

            foreach (array_diff($properties, $columns) as $extra) {
                $violations[] = ['subject' => $class, 'message' => sprintf('declares @property $%s, which %s does not have', $extra, $model->getTable())];
            }
        }

        expect(ruleUnexcused('models', 'properties', $violations))->toBe([]);
    });

    it('fills and casts only real columns, and types each @property as its cast returns', function () {
        $violations = [];

        foreach (modelSchemaModels() as $class => $model) {
            $columns = Schema::getColumnListing($model->getTable());
            $properties = modelSchemaProperties($class);

            foreach ([...$model->getFillable(), ...array_keys($model->getCasts())] as $attribute) {
                if (! in_array($attribute, $columns, true)) {
                    $violations[] = ['subject' => $class, 'message' => sprintf('fills or casts %s, which %s does not have', $attribute, $model->getTable())];
                }
            }

            $expected = [
                ...array_fill_keys($model->getDates(), modelSchemaSpec()['date_type']),
                ...array_filter(array_map(modelSchemaExpectedType(...), $model->getCasts())),
            ];

            foreach ($expected as $attribute => $type) {
                $declared = $properties[$attribute] ?? null;

                if ($declared !== null && preg_match('/(?<![\w\\\\])(?:[\w\\\\]*\\\\)?'.preg_quote($type, '/').'(?![\w\\\\])/', $declared) !== 1) {
                    $violations[] = ['subject' => $class, 'message' => sprintf('declares @property %s $%s, but its cast returns %s', $declared, $attribute, $type)];
                }
            }
        }

        expect(ruleUnexcused('models', 'casts', $violations))->toBe([]);
    });
});
