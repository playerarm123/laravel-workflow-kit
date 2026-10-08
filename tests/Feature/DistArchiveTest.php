<?php

/**
 * Every package path a rule check expects in vendor/ must survive `git archive`, the zip Composer
 * installs from Packagist. A path under an export-ignore line in .gitattributes never reaches a
 * project, so its kit-files check would fail there and pass here.
 */
it('expects no package file the dist archive leaves out', function () {
    $root = dirname(__DIR__, 2);

    preg_match_all('#^/(\S+)\s+export-ignore#m', (string) file_get_contents($root.'/.gitattributes'), $ignored);

    $expected = [];

    foreach (glob($root.'/tests/Architecture/*.php') ?: [] as $check) {
        preg_match_all("#'vendor/playerarm123/laravel-workflow-kit/([^']+)'#", (string) file_get_contents($check), $paths);
        array_push($expected, ...$paths[1]);
    }

    $missing = array_values(array_filter(
        $expected,
        fn (string $path): bool => array_filter($ignored[1], fn (string $prefix): bool => $path === $prefix || str_starts_with($path, rtrim($prefix, '/').'/')) !== [],
    ));

    expect($expected)->not->toBeEmpty()
        ->and($ignored[1])->toContain('tests/Feature')
        ->and($missing)->toBe([]);
});
