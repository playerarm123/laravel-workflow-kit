<?php

namespace Playerarm123\LaravelWorkflowKit;

/**
 * Where the kit keeps what it ships. Boost reads the guidelines and skills from these folders
 * (`resources/boost/…`) when a project lists this package in boost.json, the kit's own screens
 * read them from here too, and the generators read their stubs from here unless the project
 * publishes its own.
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
     * The folder of the kit's skills, one folder with a SKILL.md per skill.
     */
    public static function skillsPath(): string
    {
        return dirname(__DIR__).'/resources/boost/skills';
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
