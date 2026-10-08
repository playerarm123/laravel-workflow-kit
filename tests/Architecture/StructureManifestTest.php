<?php

use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureComparer;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;

require_once __DIR__.'/Support/rules.php';

/**
 * The machine-checked half of structure.md — change the two together.
 *
 * The comparison lives in StructureComparer, which reads the code through StructureReader, the
 * same reader `kit:import` writes the manifest with, and which `kit:plan` reports from too. So this
 * check, the import and the plan never disagree. The generator tests' scratch contexts and
 * resources (ruleIsScratch()) are skipped on both sides.
 *
 * @return array{
 *     kit_files: list<string>,
 * }
 */
function structureManifestSpec(): array
{
    return [
        'kit_files' => [
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/Structure/StructureReader.php',
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/Structure/StructureFiles.php',
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/Structure/StructureComparer.php',
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/Structure/StructurePlanner.php',
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/Structure/StructureMarkers.php',
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/Structure/PlansStructure.php',
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/KitImportCommand.php',
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/KitPlanCommand.php',
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/KitApplyCommand.php',
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/Structure/StructureGraph.php',
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/Structure/StructureEditor.php',
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/Structure/StructureResourceEditor.php',
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/Structure/StructureSwapper.php',
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/KitRetireCommand.php',
            'app/Providers/KitServiceProvider.php',
            'resources/views/kit/structure.blade.php',
            'resources/js/kit/structure.tsx',
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/MakeEnumCommand.php',
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/MakeValueObjectCommand.php',
            'vendor/playerarm123/laravel-workflow-kit/stubs/value-object.stub',
            'vendor/playerarm123/laravel-workflow-kit/stubs/value-object-test.stub',
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/MakeEntityMethodCommand.php',
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/Concerns/ResolvesManifestTypes.php',
            'vendor/playerarm123/laravel-workflow-kit/src/Console/Commands/Structure/KitDocs.php',
            'resources/views/kit/docs.blade.php',
        ],
    ];
}

/**
 * The comparison's differences of one check, as violations.
 *
 * @return list<array{subject: string, message: string}>
 */
function structureManifestViolations(string $check): array
{
    static $differences = null;

    $differences ??= (new StructureComparer(
        new StructureReader(ruleProjectPath()),
        new StructureFiles(ruleProjectPath()),
        fn (string $name): bool => ruleIsScratch($name),
    ))->differences();

    return array_values(array_map(
        fn (array $difference): array => ['subject' => $difference['subject'], 'message' => $difference['message']],
        array_filter($differences, fn (array $difference): bool => $difference['check'] === $check),
    ));
}

describe('structure manifest', function () {
    it('ships the manifest kit at its fixed home', function () {
        $violations = ruleKitFileViolations(structureManifestSpec()['kit_files']);

        expect(ruleUnexcused('structure', 'kit-files', $violations))->toBe([]);
    });

    it('keeps every manifest in the shape structure.md gives it', function () {
        expect(ruleUnexcused('structure', 'files', structureManifestViolations('files')))->toBe([]);
    });

    it('lists everything the code holds in the manifest', function () {
        expect(ruleUnexcused('structure', 'in-json', structureManifestViolations('in-json')))->toBe([]);
    });

    it('builds everything the manifest lists', function () {
        expect(ruleUnexcused('structure', 'in-code', structureManifestViolations('in-code')))->toBe([]);
    });

    it('describes each piece the way the code is built', function () {
        expect(ruleUnexcused('structure', 'matches', structureManifestViolations('matches')))->toBe([]);
    });
});
