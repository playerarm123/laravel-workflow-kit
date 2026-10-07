<?php

namespace Playerarm123\LaravelWorkflowKit\Tests;

use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Testbench;

/**
 * Boots the workbench app (testbench.yaml) with the kit's provider, so the generators and the
 * structure tooling run on a real Laravel app the way they run in a project.
 */
abstract class TestCase extends Testbench
{
    use WithWorkbench;
}
