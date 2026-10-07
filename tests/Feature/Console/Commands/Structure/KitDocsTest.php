<?php

use Illuminate\Contracts\Console\Kernel;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\KitDocs;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;

function kitDocs(): KitDocs
{
    return new KitDocs(WorkflowKit::guidelinesPath(), app(Kernel::class), fn (string $name): string => "/docs/{$name}");
}

describe('KitDocs', function () {
    describe('guidelines', function () {
        it('lists every guideline with its title and opening paragraph', function () {
            $guidelines = collect(kitDocs()->guidelines())->keyBy('name');

            expect($guidelines)->toHaveCount(count(glob(WorkflowKit::guidelinesPath().'/*.md')))
                ->and($guidelines['structure']['title'])->toBe('Structure')
                ->and($guidelines['structure']['summary'])->toStartWith('The structure of the project is written down in .kit/structure/')
                ->and($guidelines['layers']['title'])->toBe('Layers')
                ->and($guidelines['handlers']['summary'])->toBe('A use-case handler is the only way into the application (layers.md). Every handler follows the same outline.')
                ->and($guidelines['dates']['summary'])->toBe('A date the user reads is formatted in one file, pinned to one time zone.');
        });
    });

    describe('page', function () {
        it('renders a guideline with its outline and links to the guidelines it names', function () {
            $page = kitDocs()->page('handlers');

            expect($page['title'])->toBe('Handlers')
                ->and($page['html'])->toContain('<h2 id="the-three-shapes">The three shapes</h2>')
                ->and($page['html'])->toContain('<a href="/docs/layers">layers.md</a>')
                ->and($page['html'])->toContain('<table>')
                ->and($page['html'])->toContain('<code translate="no">__invoke()</code>')
                ->and($page['html'])->not->toContain('<code>')
                ->and(array_column($page['sections'], 'id'))->toContain('kit-files', 'the-three-shapes', 'transactions');
        });

        it('finds no page that is not a guideline', function (string $name) {
            expect(kitDocs()->page($name))->toBeNull();
        })->with(['missing' => ['nothing-here'], 'a path' => ['../../.env'], 'capitals' => ['Layers']]);
    });

    describe('commands', function () {
        it('lists the app\'s own generators and structure commands, not the framework\'s', function () {
            $names = array_column(kitDocs()->commands(), 'name');

            expect($names)->toContain('make:use-case', 'make:policy', 'kit:plan', 'kit:apply', 'kit:retire')
                ->not->toContain('make:command', 'make:job');
        });
    });
});
