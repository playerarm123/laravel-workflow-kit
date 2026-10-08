<?php

namespace Playerarm123\LaravelWorkflowKit;

/**
 * Where the kit keeps what it ships. Boost reads the guidelines and skills from these folders
 * (`resources/boost/…`) when a project lists this package in boost.json, the kit's own screens
 * read them from here too, the generators read their stubs from here unless the project
 * publishes its own, and `kit:install` copies the kit's files from here.
 */
final class WorkflowKit
{
    /**
     * The folder of the kit's guidelines, one Markdown file per rule.
     */
    public static function guidelinesPath(): string
    {
        return dirname(__DIR__).'/resources/boost/guidelines';
    }

    /**
     * The folder of the kit's guides: how to use its screens, one Markdown file per guide in
     * English (`{name}.md`) and in Thai (`{name}.th.md`), with their screenshots in `images/`.
     */
    public static function docsPath(): string
    {
        return dirname(__DIR__).'/docs';
    }

    /**
     * The folder of the kit's skills, one folder with a SKILL.md per skill.
     */
    public static function skillsPath(): string
    {
        return dirname(__DIR__).'/resources/boost/skills';
    }

    /**
     * The folder of the files the kit writes into a project (`kit:install`): `files/` the project
     * keeps as the kit ships them, `scaffold/` the kit writes once and the project owns. Each
     * holds them at their path from the project root.
     */
    public static function kitPath(): string
    {
        return dirname(__DIR__).'/resources/kit';
    }

    /**
     * A generator's stub: the project's `stubs/{name}` when it publishes one, as Laravel's own
     * generators allow, otherwise the kit's.
     */
    public static function stubPath(string $name): string
    {
        $published = base_path('stubs/'.$name);

        return is_file($published) ? $published : dirname(__DIR__).'/stubs/'.$name;
    }
}
