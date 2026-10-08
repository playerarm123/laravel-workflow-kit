<?php

use Illuminate\Contracts\Console\Kernel;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\KitDocs;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;

function kitDocs(): KitDocs
{
    return new KitDocs(
        WorkflowKit::guidelinesPath(),
        app(Kernel::class),
        fn (string $name): string => "/docs/{$name}",
        WorkflowKit::docsPath(),
        fn (string $name, string $locale): string => "/guides/{$name}?lang={$locale}",
        fn (string $file): string => "/images/{$file}",
    );
}

/**
 * The `## ` headings and the screenshots of one language of a guide, in order.
 *
 * @return array{headings: int, images: list<string>}
 */
function kitGuideOutline(string $file): array
{
    $markdown = (string) file_get_contents($file);
    preg_match_all('/^## /m', $markdown, $headings);
    preg_match_all('/\]\(images\/([^)]+)\)/', $markdown, $images);

    return ['headings' => count($headings[0]), 'images' => $images[1]];
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

    describe('guides', function () {
        it('lists each guide once, by its English title, apart from the guidelines', function () {
            $guides = collect(kitDocs()->guides())->keyBy('name');

            expect($guides->keys()->all())->toBe(['structure-screen'])
                ->and($guides['structure-screen']['title'])->toBe('The structure screen')
                ->and($guides['structure-screen']['summary'])->toStartWith('/kit/structure is where you design')
                ->and(array_column(kitDocs()->guidelines(), 'name'))->not->toContain('structure-screen');
        });

        it('renders a guide in each language, with its screenshots, its rules and the other language linked here', function (string $locale, string $title, string $other) {
            $guide = kitDocs()->guide('structure-screen', $locale);

            expect($guide['title'])->toBe($title)
                ->and($guide['locale'])->toBe($locale)
                ->and($guide['locales'])->toBe(['en', 'th'])
                ->and($guide['html'])->toContain('src="/images/structure-overview-empty.png"')
                ->and($guide['html'])->toContain('href="/docs/structure"')
                ->and($guide['html'])->not->toContain('href="../resources')
                ->and($guide['html'])->not->toContain("structure-screen{$other}.md")
                ->and(count($guide['sections']))->toBe(7)
                ->and(array_unique(array_column($guide['sections'], 'id')))->toHaveCount(7);
        })->with([
            'English' => ['en', 'The structure screen', '.th'],
            'Thai' => ['th', 'หน้าจอ structure', ''],
        ]);

        it('finds no guide by another name or in another language', function (string $name, string $locale) {
            expect(kitDocs()->guide($name, $locale))->toBeNull();
        })->with([
            'missing' => ['nothing-here', 'en'],
            'a path' => ['../README', 'en'],
            'a rule' => ['structure', 'en'],
            'another language' => ['structure-screen', 'fr'],
        ]);

        it('keeps both languages of every guide in step', function () {
            foreach (kitDocs()->guides() as $guide) {
                $english = kitGuideOutline(WorkflowKit::docsPath()."/{$guide['name']}.md");
                $thai = kitGuideOutline(WorkflowKit::docsPath()."/{$guide['name']}.th.md");

                expect($thai)->toBe($english);
            }
        });

        it('ships every screenshot a guide shows, and no other', function () {
            $shown = [];

            foreach (glob(WorkflowKit::docsPath().'/*.md') ?: [] as $file) {
                $shown = [...$shown, ...kitGuideOutline($file)['images']];
            }

            $shipped = array_map('basename', glob(WorkflowKit::docsPath().'/images/*') ?: []);
            sort($shipped);
            $shown = array_values(array_unique($shown));
            sort($shown);

            expect($shown)->toBe($shipped);
        });
    });

    describe('image', function () {
        it('finds a screenshot by its file name', function () {
            expect(kitDocs()->image('structure-overview.png'))->toBe(WorkflowKit::docsPath().'/images/structure-overview.png');
        });

        it('finds nothing outside the screenshots', function (string $file) {
            expect(kitDocs()->image($file))->toBeNull();
        })->with(['missing' => ['nothing.png'], 'a path' => ['../structure-screen.md'], 'not a png' => ['structure-screen.md'], 'capitals' => ['Structure.png']]);
    });

    describe('commands', function () {
        it('lists the app\'s own generators and structure commands, not the framework\'s', function () {
            $names = array_column(kitDocs()->commands(), 'name');

            expect($names)->toContain('make:use-case', 'make:policy', 'kit:plan', 'kit:apply', 'kit:retire')
                ->not->toContain('make:command', 'make:job');
        });
    });
});
