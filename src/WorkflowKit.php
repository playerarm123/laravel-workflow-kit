<?php

namespace Playerarm123\LaravelWorkflowKit;

/**
 * Where the kit keeps what it ships. Boost reads the guidelines and skills from these folders
 * (`resources/boost/…`) when a project lists this package in boost.json, and the kit's own
 * screens read them from here too.
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
}
