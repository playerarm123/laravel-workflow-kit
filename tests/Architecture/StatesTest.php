<?php

use App\Domain\Shared\Concerns\HasTransitions;
use App\Domain\Shared\DomainEntity;

require_once __DIR__.'/Support/rules.php';

/**
 * The machine-checked half of states.md — change the two together.
 *
 * A status is an enum named `*Status` that an entity keeps in a property and changes after it is
 * built: in any method but its constructor and its static factories. Each one declares where it
 * may go in `transitions()`, and every method that changes it asks `canBecome()` first.
 *
 * @return array{
 *     kit_files: list<string>,
 *     domain: string,
 *     status_suffix: string,
 *     trait: class-string,
 *     transitions_method: string,
 *     guard_call: string,
 * }
 */
function statesSpec(): array
{
    return [
        'kit_files' => [
            'app/Domain/Shared/Concerns/HasTransitions.php',
            'tests/Unit/Domain/Shared/Concerns/HasTransitionsTest.php',
        ],
        'domain' => 'app/Domain',
        'status_suffix' => 'Status',
        'trait' => HasTransitions::class,
        'transitions_method' => 'transitions',
        'guard_call' => 'canBecome(',
    ];
}

/**
 * Every method of an entity that changes a status, with the enum it changes.
 *
 * @return list<array{entity: class-string, method: ReflectionMethod, status: class-string<UnitEnum>}>
 */
function statesChanges(): array
{
    $changes = [];

    foreach (ruleConcreteClassesIn(statesSpec()['domain']) as $class) {
        if (! is_subclass_of($class, DomainEntity::class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        $methods = array_values(array_filter(
            $reflection->getMethods(),
            fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $class && ! $method->isStatic() && $method->getName() !== '__construct',
        ));
        $all = array_fill_keys(array_map(fn (ReflectionMethod $method): string => $method->getName(), $reflection->getMethods()), true);

        foreach ($reflection->getProperties() as $property) {
            $type = $property->getType();

            if (! $type instanceof ReflectionNamedType || ! enum_exists($type->getName()) || ! str_ends_with($type->getName(), statesSpec()['status_suffix'])) {
                continue;
            }

            foreach ($methods as $method) {
                $own = $all;

                if (preg_match('/\$this->'.preg_quote($property->getName(), '/').'\s*=(?![=>])/', ruleMethodBodyFollowing($method, $own)) === 1) {
                    $changes[] = ['entity' => $class, 'method' => $method, 'status' => $type->getName()];
                }
            }
        }
    }

    return $changes;
}

/**
 * The status enums some entity changes, each once.
 *
 * @return list<class-string<UnitEnum>>
 */
function statesEnums(): array
{
    return array_values(array_unique(array_column(statesChanges(), 'status')));
}

describe('states', function () {
    it('ships the states kit at its fixed home', function () {
        $violations = [];

        foreach (statesSpec()['kit_files'] as $file) {
            if (! is_file(ruleProjectPath($file))) {
                $violations[] = ['subject' => $file, 'message' => 'is missing — copy it from the kit'];
            }
        }

        expect(ruleUnexcused('states', 'kit-files', $violations))->toBe([]);
    });

    it('declares the transitions of every status an entity changes', function () {
        $violations = [];
        $method = statesSpec()['transitions_method'];

        foreach (statesEnums() as $enum) {
            $reflection = new ReflectionEnum($enum);

            if (! in_array(statesSpec()['trait'], class_uses($enum), true)) {
                $violations[] = ['subject' => $enum, 'message' => sprintf('an entity changes it, so it must use %s', class_basename(statesSpec()['trait']))];

                continue;
            }

            $declared = $reflection->getMethod($method);
            $seen = [$method => true];

            if ($declared->getDeclaringClass()->getName() !== $enum || preg_match('/\bmatch\s*\(\s*\$this\s*\)/', ruleMethodBodyFollowing($declared, $seen)) !== 1) {
                $violations[] = ['subject' => $enum, 'message' => sprintf('must write %s() as a match ($this) over every case', $method)];
            }
        }

        expect(ruleUnexcused('states', 'declares', $violations))->toBe([]);
    });

    it('never lists a status as its own next one', function () {
        $violations = [];

        foreach (statesEnums() as $enum) {
            if (! in_array(statesSpec()['trait'], class_uses($enum), true)) {
                continue;
            }

            foreach ($enum::cases() as $case) {
                if (in_array($case, $case->transitions(), true)) {
                    $violations[] = ['subject' => $enum, 'message' => sprintf('%s lists itself next — staying put is no change, so it is refused', $case->name)];
                }
            }
        }

        expect(ruleUnexcused('states', 'self-loop', $violations))->toBe([]);
    });

    it('asks canBecome() before every change of a status', function () {
        $violations = [];

        foreach (statesChanges() as $change) {
            if (! str_contains(ruleMethodBodyFollowing($change['method']), statesSpec()['guard_call'])) {
                $violations[] = [
                    'subject' => $change['entity'].'::'.$change['method']->getName(),
                    'message' => sprintf('changes its %s without asking canBecome() first, directly or through an assertion of its own class', class_basename($change['status'])),
                ];
            }
        }

        expect(ruleUnexcused('states', 'guarded', $violations))->toBe([]);
    });
});
