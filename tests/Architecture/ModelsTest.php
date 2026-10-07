<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

require_once __DIR__.'/Support/rules.php';

/**
 * The machine-checked half of models.md that reads the source — change the
 * two together. The half that compares each model with its table needs a database, so it
 * lives in tests/Feature/ModelsTest.php.
 *
 * `key_trait` is the kit trait that keys a model on a uuid it never mints and refuses a
 * route value that is not one. `model_stub` and `factory_stub` are what `make:model` copies;
 * each must already carry the shape its check asks for. `allowed_methods` are the methods a model may declare besides
 * its relations.
 *
 * @return array{
 *     kit_files: list<string>,
 *     model_stub: string,
 *     factory_stub: string,
 *     models_path: string,
 *     factories_path: string,
 *     seeders_path: string,
 *     providers_path: string,
 *     key_trait: class-string,
 *     key_banned: array<string, string>,
 *     attributes: array<string, string>,
 *     unguard: array<string, string>,
 *     events: array<string, string>,
 *     allowed_methods: list<string>,
 *     factory_id: string,
 *     factory_banned: array<string, string>,
 *     seeder_banned: array<string, string>,
 * }
 */
function modelsSpec(): array
{
    return [
        'kit_files' => [
            'app/Models/Concerns/KeyedByUuid.php',
            'stubs/model.stub',
            'stubs/factory.stub',
            'tests/Feature/ModelsTest.php',
            'tests/Feature/Models/Concerns/KeyedByUuidTest.php',
        ],
        'model_stub' => 'stubs/model.stub',
        'factory_stub' => 'stubs/factory.stub',
        'models_path' => 'app/Models',
        'factories_path' => 'database/factories',
        'seeders_path' => 'database/seeders',
        'providers_path' => 'app/Providers',
        'key_trait' => 'App\Models\Concerns\KeyedByUuid',
        'key_banned' => [
            '/\bHas(Version4)?Uuids\b|\bHasUlids\b/' => 'mints its own id — IdGenerator is the one id source, use KeyedByUuid',
            '/\$(incrementing|keyType)\b/' => 'declares the key by hand — use KeyedByUuid, which also refuses a route value that is not a uuid',
            '/\$primaryKey\b/' => 'changes the primary key — every table is keyed on id',
        ],
        'attributes' => [
            '/\$(fillable|guarded)\b/' => 'uses a property — declare mass-assignable columns with #[Fillable([...])]',
            '/\$(hidden|visible)\b/' => 'uses a property — declare hidden columns with #[Hidden([...])]',
            '/\$casts\b/' => 'uses the $casts property — declare casts in a casts() method',
        ],
        'unguard' => [
            '/::unguard\s*\(/' => 'turns off mass-assignment protection — declare #[Fillable([...])] instead',
        ],
        'events' => [
            '/#\[\s*\\\\?(\w+\\\\)*ObservedBy\b/' => 'observes model events — repositories write with upsert and insert, which fire none',
            '/\$dispatchesEvents\b/' => 'dispatches model events — repositories write with upsert and insert, which fire none',
        ],
        'allowed_methods' => ['casts', 'getRouteKeyName', 'resolveRouteBinding'],
        'factory_id' => '/[\'"]id[\'"]\s*=>\s*fake\(\)\s*->\s*uuid\(\)/',
        'factory_banned' => [
            '/\bStr::(uuid7?|orderedUuid|ulid)\s*\(/' => 'mints an id the way production does — use fake()->uuid()',
            '/\bIdGenerator\b/' => 'asks IdGenerator — a test that fixes the next id would hand it to every row; use fake()->uuid()',
        ],
        'seeder_banned' => [
            '/\bfake\s*\(|\$this->faker\b/' => 'uses faker, which production installs without (require-dev)',
            '/\bStr::(uuid7?|orderedUuid|ulid)\s*\(/' => 'mints its own id — call a handler, or take the id from IdGenerator',
        ],
    ];
}

/**
 * The model files this rule reads.
 *
 * @return list<string>
 */
function modelsFiles(): array
{
    return ruleSourceFiles(modelsSpec()['models_path'], ['php']);
}

/**
 * Every model class: the concrete Eloquent models under the models path.
 *
 * @return array<string, class-string<Model>> file => class
 */
function modelsClasses(): array
{
    $classes = [];

    foreach (modelsFiles() as $file) {
        $class = ruleClassOf($file);

        if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $classes[$file] = $class;
        }
    }

    return $classes;
}

/**
 * Every line of the given files that matches one of the patterns, reported against the
 * subject the callback names for the file.
 *
 * @param  list<string>  $files
 * @param  array<string, string>  $patterns
 * @return list<array{subject: string, message: string}>
 */
function modelsMatches(array $files, array $patterns, ?Closure $subject = null): array
{
    $violations = [];

    foreach ($patterns as $pattern => $message) {
        foreach (ruleCodeMatches($files, $pattern, $message) as $violation) {
            $violations[] = ['subject' => $subject ? $subject($violation['subject']) : $violation['subject'], 'message' => $violation['message']];
        }
    }

    return $violations;
}

describe('models', function () {
    it('ships the kit files', function () {
        $violations = [];

        foreach (modelsSpec()['kit_files'] as $file) {
            if (! is_file(ruleProjectPath($file))) {
                $violations[] = ['subject' => $file, 'message' => 'is missing — copy it from the kit'];
            }
        }

        $modelStub = (string) @file_get_contents(ruleProjectPath(modelsSpec()['model_stub']));

        if ($modelStub !== '' && preg_match('/^\s*use\s+[^;]*\b'.class_basename(modelsSpec()['key_trait']).'\b[^;]*;/m', $modelStub) !== 1) {
            $violations[] = ['subject' => modelsSpec()['model_stub'], 'message' => 'must use '.modelsSpec()['key_trait']];
        }

        $factoryStub = (string) @file_get_contents(ruleProjectPath(modelsSpec()['factory_stub']));

        if ($factoryStub !== '' && preg_match(modelsSpec()['factory_id'], $factoryStub) !== 1) {
            $violations[] = ['subject' => modelsSpec()['factory_stub'], 'message' => "must fill 'id' => fake()->uuid()"];
        }

        expect(ruleUnexcused('models', 'kit-files', $violations))->toBe([]);
    });

    it('keys every model on a uuid that only IdGenerator mints', function () {
        $violations = modelsMatches(array_keys(modelsClasses()), modelsSpec()['key_banned'], fn (string $file): string => modelsClasses()[$file]);

        foreach (modelsClasses() as $class) {
            if (! in_array(modelsSpec()['key_trait'], class_uses_recursive($class), true)) {
                $violations[] = ['subject' => $class, 'message' => 'must use '.modelsSpec()['key_trait'].' — the uuid key IdGenerator mints'];
            }
        }

        expect(ruleUnexcused('models', 'key', $violations))->toBe([]);
    });

    it('declares fillable, hidden and casts one way', function () {
        $violations = [
            ...modelsMatches(array_keys(modelsClasses()), modelsSpec()['attributes'], fn (string $file): string => modelsClasses()[$file]),
            ...modelsMatches(ruleSourceFiles('app', ['php']), modelsSpec()['unguard']),
        ];

        expect(ruleUnexcused('models', 'attributes', $violations))->toBe([]);
    });

    it('holds casts, typed relations and route binding, and nothing else', function () {
        $violations = modelsMatches(array_keys(modelsClasses()), modelsSpec()['events'], fn (string $file): string => modelsClasses()[$file]);

        foreach (modelsClasses() as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods() as $method) {
                if ($method->getFileName() !== $reflection->getFileName() || in_array($method->getName(), modelsSpec()['allowed_methods'], true)) {
                    continue;
                }

                $relation = $method->getReturnType() instanceof ReflectionNamedType
                    && is_a($method->getReturnType()->getName(), Relation::class, true)
                    ? $method->getReturnType()->getName()
                    : null;

                if ($relation === null) {
                    $violations[] = ['subject' => $class, 'message' => sprintf('declares %s() — a model holds casts(), relations with a native return type, and route binding only', $method->getName())];

                    continue;
                }

                $short = class_basename($relation);

                if (preg_match('/@return\s+\\\\?([\w\\\\]*\\\\)?'.$short.'<[^>]+>/', (string) $method->getDocComment()) !== 1) {
                    $violations[] = ['subject' => $class, 'message' => sprintf('%s() needs a generic docblock: @return %s<{Related}, $this>', $method->getName(), $short)];
                }
            }
        }

        expect(ruleUnexcused('models', 'members', $violations))->toBe([]);
    });

    it('opens every model docblock on its id', function () {
        $violations = [];

        foreach (modelsClasses() as $class) {
            if (preg_match('/@property\s+string\s+\$id\b/', (string) (new ReflectionClass($class))->getDocComment()) !== 1) {
                $violations[] = ['subject' => $class, 'message' => 'has no @property block, or it lacks @property string $id — give every column one line'];
            }
        }

        expect(ruleUnexcused('models', 'docblock', $violations))->toBe([]);
    });

    it('maps every polymorphic type through an enforced morph map', function () {
        $morphs = array_values(array_filter(
            modelsClasses(),
            fn (string $file): bool => str_contains(ruleCodeWithoutComments($file), 'MorphTo'),
            ARRAY_FILTER_USE_KEY,
        ));

        $mapped = ruleCodeMatches(ruleSourceFiles(modelsSpec()['providers_path'], ['php']), '/Relation::enforceMorphMap\s*\(/', '') !== [];

        $violations = $mapped ? [] : array_map(
            fn (string $class): array => ['subject' => $class, 'message' => 'declares a MorphTo but no provider calls Relation::enforceMorphMap() — map every value of the {x}_type enum'],
            $morphs,
        );

        expect(ruleUnexcused('models', 'morph-map', $violations))->toBe([]);
    });

    it('gives every model a factory that fills a fake uuid id', function () {
        $factories = ruleSourceFiles(modelsSpec()['factories_path'], ['php']);
        $violations = modelsMatches($factories, modelsSpec()['factory_banned']);

        foreach (modelsClasses() as $file => $class) {
            $model = class_basename($class);
            $factory = modelsSpec()['factories_path'].'/'.$model.'Factory.php';

            if (preg_match('/@use\s+HasFactory<\\\\?([\w\\\\]*\\\\)?'.$model.'Factory>/', (string) file_get_contents(ruleProjectPath($file))) !== 1) {
                $violations[] = ['subject' => $class, 'message' => sprintf('must use HasFactory with /** @use HasFactory<%sFactory> */', $model)];
            }

            if (! is_file(ruleProjectPath($factory))) {
                $violations[] = ['subject' => $factory, 'message' => sprintf('is missing — every model has a factory (php artisan make:factory %sFactory)', $model)];

                continue;
            }

            $source = (string) file_get_contents(ruleProjectPath($factory));

            if (preg_match('/@extends\s+Factory<\\\\?([\w\\\\]*\\\\)?'.$model.'>/', $source) !== 1) {
                $violations[] = ['subject' => $factory, 'message' => sprintf('must be declared @extends Factory<%s>', $model)];
            }

            if (preg_match(modelsSpec()['factory_id'], ruleCodeWithoutComments($factory)) !== 1) {
                $violations[] = ['subject' => $factory, 'message' => "must fill 'id' => fake()->uuid() in definition() — the model mints no id"];
            }
        }

        expect(ruleUnexcused('models', 'factory', $violations))->toBe([]);
    });

    it('seeds without faker and mints no id of its own', function () {
        $violations = modelsMatches(ruleSourceFiles(modelsSpec()['seeders_path'], ['php']), modelsSpec()['seeder_banned']);

        expect(ruleUnexcused('models', 'seeders', $violations))->toBe([]);
    });
});
