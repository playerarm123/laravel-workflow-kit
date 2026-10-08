<?php

namespace App\Providers;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\KitDocs;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureComparer;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureEditor;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureFiles;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureGraph;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureMarkers;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructurePlanner;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureReader;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureResourceEditor;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The kit's own screens (structure.md), served beside the app the way Horizon is: routes of their
 * own that render a Blade view and its own Vite entry, outside Inertia and the app's pages. They
 * read and write files on the developer's disk, so they exist on a local environment only.
 *
 * The forms post flat: a piece's own keys sit beside `version`, `section`, `previous` and `name`, so
 * an error the editor names by field lands under that field.
 *
 * A change the editor refuses becomes a ValidationException, which ExceptionResponses answers as a
 * 422 with the errors by field. A change it writes is answered with the graph drawn again.
 */
class KitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->environment('local')) {
            return;
        }

        Route::middleware('web')->get('kit/docs/{page?}', function (?string $page = null): View {
            $docs = new KitDocs(WorkflowKit::guidelinesPath(), $this->app->make(Kernel::class), fn (string $name): string => route('kit.docs', ['page' => $name]));
            $shown = $page === null ? null : $docs->page($page);

            if ($page !== null && $shown === null) {
                throw new NotFoundHttpException;
            }

            return view('kit.docs', [
                'guidelines' => $docs->guidelines(),
                'page' => $shown,
                'commands' => $page === null ? $docs->commands() : [],
            ]);
        })->name('kit.docs');

        Route::middleware('web')->prefix('kit/structure')->name('kit.structure')->group(function (): void {
            Route::get('/', fn (): View => view('kit.structure', [
                'graph' => $this->structureGraph()->graph(),
                'endpoints' => [
                    'createContext' => route('kit.structure.contexts.store'),
                    'savePiece' => route('kit.structure.pieces.store', ['context' => '__CONTEXT__']),
                    'removePiece' => route('kit.structure.pieces.remove', ['context' => '__CONTEXT__']),
                    'replace' => route('kit.structure.replacements.store', ['context' => '__CONTEXT__']),
                    'cancelReplacement' => route('kit.structure.replacements.cancel', ['context' => '__CONTEXT__']),
                    'saveMethod' => route('kit.structure.methods.store', ['context' => '__CONTEXT__']),
                    'removeMethod' => route('kit.structure.methods.remove', ['context' => '__CONTEXT__']),
                    'createResource' => route('kit.structure.resources.store'),
                    'saveResource' => route('kit.structure.resources.update', ['resource' => '__RESOURCE__']),
                    'saveResourcePiece' => route('kit.structure.resource-pieces.store', ['resource' => '__RESOURCE__']),
                    'removeResourcePiece' => route('kit.structure.resource-pieces.remove', ['resource' => '__RESOURCE__']),
                    'docs' => route('kit.docs'),
                ],
            ]))->name('');

            Route::post('contexts', fn (Request $request): JsonResponse => $this->answer(
                $this->structureEditor()->createContext($request->string('name')->toString()),
            ))->name('.contexts.store');

            Route::post('contexts/{context}/pieces', fn (Request $request, string $context): JsonResponse => $this->answer(
                $this->structureEditor()->savePiece(
                    $context,
                    $request->string('version')->toString(),
                    $request->string('section')->toString(),
                    $request->filled('previous') ? $request->string('previous')->toString() : null,
                    $request->string('name')->toString(),
                    $request->only(array_keys(StructureFiles::SECTIONS[$request->string('section')->toString()] ?? [])),
                ),
            ))->name('.pieces.store');

            Route::post('contexts/{context}/pieces/remove', fn (Request $request, string $context): JsonResponse => $this->answer(
                $this->structureEditor()->removePiece(
                    $context,
                    $request->string('version')->toString(),
                    $request->string('section')->toString(),
                    $request->string('name')->toString(),
                ),
            ))->name('.pieces.remove');

            Route::post('contexts/{context}/replace', fn (Request $request, string $context): JsonResponse => $this->answer(
                $this->structureEditor()->replace(
                    $context,
                    $request->string('version')->toString(),
                    $request->string('section')->toString(),
                    $request->string('name')->toString(),
                    $request->string('replacement')->toString(),
                ),
            ))->name('.replacements.store');

            Route::post('contexts/{context}/replace/cancel', fn (Request $request, string $context): JsonResponse => $this->answer(
                $this->structureEditor()->cancelReplacement(
                    $context,
                    $request->string('version')->toString(),
                    $request->string('section')->toString(),
                    $request->string('name')->toString(),
                ),
            ))->name('.replacements.cancel');

            Route::post('contexts/{context}/methods', fn (Request $request, string $context): JsonResponse => $this->answer(
                $this->structureEditor()->saveMethod(
                    $context,
                    $request->string('version')->toString(),
                    $request->string('entity')->toString(),
                    $request->filled('previous') ? $request->string('previous')->toString() : null,
                    $request->string('name')->toString(),
                    is_array($request->input('params')) ? $request->input('params') : [],
                    is_array($request->input('throws')) ? $request->input('throws') : [],
                ),
            ))->name('.methods.store');

            Route::post('contexts/{context}/methods/remove', fn (Request $request, string $context): JsonResponse => $this->answer(
                $this->structureEditor()->removeMethod(
                    $context,
                    $request->string('version')->toString(),
                    $request->string('entity')->toString(),
                    $request->string('name')->toString(),
                ),
            ))->name('.methods.remove');

            Route::post('resources', fn (Request $request): JsonResponse => $this->answer(
                $this->resourceEditor()->createResource(
                    $request->string('name')->toString(),
                    $request->filled('model') ? $request->string('model')->toString() : null,
                ),
            ))->name('.resources.store');

            Route::post('resources/{resource}', fn (Request $request, string $resource): JsonResponse => $this->answer(
                $this->resourceEditor()->saveResource(
                    $resource,
                    $request->string('version')->toString(),
                    $request->filled('model') ? $request->string('model')->toString() : null,
                    $request->has('policy') && is_array($request->input('policy')) ? array_values(array_filter($request->input('policy'), is_string(...))) : null,
                ),
            ))->name('.resources.update');

            Route::post('resources/{resource}/pieces', fn (Request $request, string $resource): JsonResponse => $this->answer(
                $this->resourceEditor()->savePiece(
                    $resource,
                    $request->string('version')->toString(),
                    $request->string('section')->toString(),
                    $request->filled('previous') ? $request->string('previous')->toString() : null,
                    $request->string('name')->toString(),
                    $request->only(['useCases', 'row', 'bulk', 'kind']),
                ),
            ))->name('.resource-pieces.store');

            Route::post('resources/{resource}/pieces/remove', fn (Request $request, string $resource): JsonResponse => $this->answer(
                $this->resourceEditor()->removePiece(
                    $resource,
                    $request->string('version')->toString(),
                    $request->string('section')->toString(),
                    $request->string('name')->toString(),
                ),
            ))->name('.resource-pieces.remove');
        });
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function answer(array $errors): JsonResponse
    {
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new JsonResponse(['graph' => $this->structureGraph()->graph()]);
    }

    private function structureEditor(): StructureEditor
    {
        return new StructureEditor(new StructureFiles(base_path()), new StructureReader(base_path()));
    }

    private function resourceEditor(): StructureResourceEditor
    {
        return new StructureResourceEditor(new StructureFiles(base_path()), new StructureReader(base_path()));
    }

    private function structureGraph(): StructureGraph
    {
        $root = base_path();

        return new StructureGraph(
            new StructureReader($root),
            new StructureFiles($root),
            new StructurePlanner(new StructureReader($root), new StructureFiles($root), new StructureMarkers($root)),
            new StructureComparer(new StructureReader($root), new StructureFiles($root), fn (string $name): bool => false),
        );
    }
}
