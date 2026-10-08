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
 *
 * Beside the rules it serves the kit's guides, which explain how to use its screens: the same
 * Markdown the repository's `docs/` shows on GitHub, in English and in Thai, with its screenshots.
 */
final class KitDocs
{
    private const string NAME = '/^[a-z][a-z0-9-]*$/';

    private const string IMAGE = '/^[a-z0-9][a-z0-9-]*\.png$/';

    /**
     * The languages a guide is written in, by the suffix its file carries.
     */
    public const array LOCALES = ['en' => '', 'th' => '.th'];

    /**
     * @param  string  $directory  the folder of the guidelines, one `{name}.md` each
     * @param  Closure(string): string  $linkTo  the url of a guideline's page, by its name
     * @param  string|null  $guidesDirectory  the folder of the guides, `{name}.md` and `{name}.th.md`, with `images/`
     * @param  (Closure(string, string): string)|null  $guideLinkTo  the url of a guide, by its name and language
     * @param  (Closure(string): string)|null  $imageUrl  the url of a guide's screenshot, by its file name
     */
    public function __construct(
        private readonly string $directory,
        private readonly Kernel $console,
        private readonly Closure $linkTo,
        private readonly ?string $guidesDirectory = null,
        private readonly ?Closure $guideLinkTo = null,
        private readonly ?Closure $imageUrl = null,
    ) {}

    /**
     * Every guide, with its title and the paragraph that opens it, in English.
     *
     * @return list<array{name: string, title: string, summary: string}>
     */
    public function guides(): array
    {
        if ($this->guidesDirectory === null) {
            return [];
        }

        $guides = [];

        foreach (glob($this->guidesDirectory.'/*.md') ?: [] as $file) {
            $name = basename($file, '.md');

            if (preg_match(self::NAME, $name) !== 1) {
                continue;
            }

            $markdown = $this->withoutLanguageLink($name, StructureFiles::text($file));
            $guides[] = ['name' => $name, 'title' => $this->titleOf($markdown, $name), 'summary' => $this->summaryOf($markdown)];
        }

        usort($guides, fn (array $a, array $b): int => strcmp($a['title'], $b['title']));

        return $guides;
    }

    /**
     * One guide rendered in one language, as page() renders a rule. Its screenshots point at the
     * image route, its link to the other language at that language's page, and its links to the
     * guidelines at their pages here.
     *
     * @return array{name: string, title: string, html: string, sections: list<array{id: string, title: string}>, locale: string, locales: list<string>}|null
     */
    public function guide(string $name, string $locale = 'en'): ?array
    {
        if ($this->guidesDirectory === null || preg_match(self::NAME, $name) !== 1 || ! array_key_exists($locale, self::LOCALES)) {
            return null;
        }

        $file = $this->guidesDirectory.'/'.$name.self::LOCALES[$locale].'.md';

        if (! is_file($file)) {
            return null;
        }

        $markdown = $this->withoutLanguageLink($name, StructureFiles::text($file));
        ['html' => $html, 'sections' => $sections] = $this->render($markdown);

        $html = (string) preg_replace_callback('/(src|href)="([^"]*)"/', function (array $match) use ($name): string {
            [, $attribute, $target] = $match;

            if ($attribute === 'src' && preg_match('#^images/([^/]+)$#', $target, $image) === 1 && $this->imageUrl !== null) {
                return sprintf('src="%s"', e(($this->imageUrl)($image[1])));
            }

            if (preg_match('#^\.\./resources/boost/guidelines/([a-z][a-z0-9-]*)\.md(\#[\w-]*)?$#', $target, $rule) === 1) {
                return sprintf('href="%s%s"', e(($this->linkTo)($rule[1])), $rule[2] ?? '');
            }

            if (preg_match('#^('.preg_quote($name, '#').')((?:\.th)?)\.md$#', $target, $sibling) === 1 && $this->guideLinkTo !== null) {
                return sprintf('href="%s"', e(($this->guideLinkTo)($name, $sibling[2] === '' ? 'en' : 'th')));
            }

            return $match[0];
        }, $html);

        $locales = array_values(array_filter(array_keys(self::LOCALES), fn (string $each): bool => is_file($this->guidesDirectory.'/'.$name.self::LOCALES[$each].'.md')));

        return ['name' => $name, 'title' => $this->titleOf($markdown, $name), 'html' => $html, 'sections' => $sections, 'locale' => $locale, 'locales' => $locales];
    }

    /**
     * The file of a guide's screenshot, by its name, or null when there is none by that name.
     */
    public function image(string $file): ?string
    {
        if ($this->guidesDirectory === null || preg_match(self::IMAGE, $file) !== 1) {
            return null;
        }

        $path = $this->guidesDirectory.'/images/'.$file;

        return is_file($path) ? $path : null;
    }

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
        ['html' => $html, 'sections' => $sections] = $this->render($markdown);

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

    /**
     * Markdown as the page shows it: each section with an id for the outline, every mention of a
     * guideline (`layers.md`) outside a link turned into one, and code marked `translate="no"`.
     *
     * @return array{html: string, sections: list<array{id: string, title: string}>}
     */
    private function render(string $markdown): array
    {
        $sections = [];

        $html = (string) preg_replace_callback('#<h2>(.*?)</h2>#s', function (array $match) use (&$sections): string {
            $title = strip_tags($match[1]);
            // A heading in a script Str::slug drops, Thai for one, still needs an id of its own.
            $id = Str::slug($title) ?: 'section-'.(count($sections) + 1);
            $sections[] = ['id' => $id, 'title' => html_entity_decode($title, ENT_QUOTES)];

            return sprintf('<h2 id="%s">%s</h2>', $id, $match[1]);
        }, Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]));

        $names = array_column($this->guidelines(), 'name');
        $html = (string) preg_replace_callback('#<a\b[^>]*>.*?</a>|<[^>]*>|\b([a-z][a-z0-9-]*)\.md\b#s', fn (array $match): string => isset($match[1]) && in_array($match[1], $names, true)
            ? sprintf('<a href="%s">%s.md</a>', e(($this->linkTo)($match[1])), $match[1])
            : $match[0], $html);

        $html = str_replace(['<pre>', '<code>'], ['<pre translate="no">', '<code translate="no">'], $html);

        return ['html' => $html, 'sections' => $sections];
    }

    /**
     * A guide without the line that links its other language, which GitHub needs and the page
     * replaces with its own switch.
     */
    private function withoutLanguageLink(string $name, string $markdown): string
    {
        return (string) preg_replace('/^\[[^\]]+\]\('.preg_quote($name, '/').'(?:\.th)?\.md\)[ \t]*\n+/m', '', $markdown);
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
