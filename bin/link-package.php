<?php

/**
 * Links vendor/playerarm123/laravel-workflow-kit to this repository, so the workbench reads the
 * kit from the path every project reads it from: the ESLint imports, the PHPStan include and the
 * Architecture checks' kit files all name vendor/playerarm123/laravel-workflow-kit/….
 */
$link = __DIR__.'/../vendor/playerarm123/laravel-workflow-kit';

if (is_link($link) || is_dir($link)) {
    return;
}

if (! is_dir(dirname($link))) {
    mkdir(dirname($link), 0755, true);
}

symlink('../..', $link);
