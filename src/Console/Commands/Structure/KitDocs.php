<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

/**
 * The kit's rules as pages (structure.md): each guideline in the folder it is given, the workflow
 * kit's own, rendered as it is on disk, so the kit's docs screen never lags behind the rule it
 * shows, and the `make:*` and `kit:*` commands the project has, read from the console itself.
 */
final class KitDocs
{
    private const string NAME = '/^[a-z][a-z0-9-]*$/';

    /**
     * @param  string  $directory  the folder of the guidelines, one `{name}.md` each
     * @param  Closure(string): string  $linkTo  the url of a guideline's page, by its name
     */
    public function __construct(
        private readonly string $directory,
        private readonly Kernel $console,
        private readonly Closure $linkTo,
    ) {}

    /**
     * Every guideline, with its title and the paragraph that opens it.
     *
     * @return list<array{name: string, title: string, summary: string}>
     */
    public function guidelines(): array
    {
        $guidelines = [];

        foreach (glob($this->directory.'/*.md') ?: [] as $file) {
            $markdown = StructureFiles::text($file);
            $guidelines[] = [
                'name' => basename($file, '.md'),
                'title' => $this->titleOf($markdown, basename($file, '.md')),
                'summary' => $this->summaryOf($markdown),
            ];
        }

        usort($guidelines, fn (array $a, array $b): int => strcmp($a['title'], $b['title']));

        return $guidelines;
    }

    /**
     * One guideline rendered: its sections for the page's outline, every mention of another
     * guideline (`layers.md`) turned into a link to it, and its code marked `translate="no"`, so a
     * browser's translation of the page leaves class names, paths and commands as they are.
     *
     * @return array{name: string, title: string, html: string, sections: list<array{id: string, title: string}>}|null
     */
    public function page(string $name): ?array
    {
        $file = $this->directory.'/'.$name.'.md';

        if (preg_match(self::NAME, $name) !== 1 || ! is_file($file)) {
            return null;
        }

        $markdown = StructureFiles::text($file);
        $sections = [];

        $html = (string) preg_replace_callback('#<h2>(.*?)</h2>#s', function (array $match) use (&$sections): string {
            $title = strip_tags($match[1]);
            $id = Str::slug($title);
            $sections[] = ['id' => $id, 'title' => html_entity_decode($title, ENT_QUOTES)];

            return sprintf('<h2 id="%s">%s</h2>', $id, $match[1]);
        }, Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]));

        $names = array_column($this->guidelines(), 'name');
        $html = (string) preg_replace_callback('/<[^>]*>|\b([a-z][a-z0-9-]*)\.md\b/', fn (array $match): string => isset($match[1]) && in_array($match[1], $names, true)
            ? sprintf('<a href="%s">%s.md</a>', e(($this->linkTo)($match[1])), $match[1])
            : $match[0], $html);

        $html = str_replace(['<pre>', '<code>'], ['<pre translate="no">', '<code translate="no">'], $html);

        return ['name' => $name, 'title' => $this->titleOf($markdown, $name), 'html' => $html, 'sections' => $sections];
    }

    /**
     * The scaffolding and structure commands the project's console has: `make:*` and `kit:*`
     * from the kit or the app itself, not from the framework.
     *
     * @return list<array{name: string, description: string}>
     */
    public function commands(): array
    {
        $commands = [];

        foreach ($this->console->all() as $name => $command) {
            if ($command instanceof Command && (str_starts_with($command::class, 'App\\') || str_starts_with($command::class, 'Playerarm123\\LaravelWorkflowKit\\')) && (str_starts_with($name, 'make:') || str_starts_with($name, 'kit:'))) {
                $commands[] = ['name' => $name, 'description' => $command->getDescription()];
            }
        }

        usort($commands, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $commands;
    }

    private function titleOf(string $markdown, string $fallback): string
    {
        return preg_match('/^# (.+)$/m', $markdown, $match) === 1 ? trim($match[1]) : Str::headline($fallback);
    }

    /**
     * The first paragraph after the title, as plain text: up to a list that follows it, and ending
     * as a sentence where it leads into an example.
     */
    private function summaryOf(string $markdown): string
    {
        foreach (preg_split('/\n\s*\n/', $markdown) ?: [] as $block) {
            $block = trim($block);

            if ($block !== '' && ! str_starts_with($block, '#') && ! str_starts_with($block, '```') && ! str_starts_with($block, '|')) {
                $prose = preg_split('/\n\s*[-*] /', $block)[0] ?? $block;
                $text = trim((string) preg_replace('/[`*_]/', '', (string) preg_replace('/\s+/', ' ', $prose)));

                return str_ends_with($text, ':') ? substr($text, 0, -1).'.' : $text;
            }
        }

        return '';
    }
}
