<?php

require_once __DIR__.'/Support/rules.php';

/**
 * The machine-checked half of media.md — change the two together.
 *
 * Every check holds only where the project installed the feature (`kit:install --with=media`,
 * recorded in .kit/features.json). The kit files are read off the feature's own folder in the
 * kit, so a file added to the feature is checked without a line here.
 *
 * @return array{
 *     feature: string,
 *     kit: string,
 *     migration: string,
 *     ports: list<class-string>,
 *     controllers: list<string>,
 *     routes: string,
 *     lang_files: string,
 * }
 */
function mediaSpec(): array
{
    return [
        'feature' => 'media',
        'kit' => dirname(__DIR__, 2).'/resources/kit/features/media',
        'migration' => 'database/migrations/*_create_media_tables.php',
        'ports' => [
            'App\Domain\Media\MediaItem\MediaItemRepository',
            'App\Domain\Media\MediaFolder\MediaFolderRepository',
            'App\Application\Media\MediaStorage',
            'App\Application\Media\MediaQuota',
            'App\Application\Media\MediaFolderDirectory',
            'App\Application\Media\UseCases\ListMediaItems\ListMediaItemsQuery',
        ],
        'controllers' => ['MediaItemController', 'MediaFolderController', 'MediaItemBulkMoveController'],
        'routes' => 'routes/*.php',
        'lang_files' => 'lang/*.json',
    ];
}

/**
 * Every path the feature writes into a project, from its root, its migration as the pattern that
 * finds it under any timestamp.
 *
 * @return list<string>
 */
function mediaKitPaths(): array
{
    $paths = [];

    foreach (['files', 'scaffold'] as $folder) {
        $root = mediaSpec()['kit'].'/'.$folder;

        if (! is_dir($root)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $paths[] = str_starts_with($relative, 'database/migrations/') ? mediaSpec()['migration'] : $relative;
        }
    }

    sort($paths);

    return $paths;
}

/**
 * The words of the feature, by key, as its lang/en.json ships them.
 *
 * @return array<string, string>
 */
function mediaWords(): array
{
    $path = mediaSpec()['kit'].'/lang/en.json';
    $words = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

    return is_array($words) ? $words : [];
}

describe('media', function () {
    beforeEach(function () {
        if (! ruleFeatureEnabled(mediaSpec()['feature'])) {
            $this->markTestSkipped('The media feature is not installed (.kit/features.json).');
        }
    });

    it('ships the image library at its fixed home', function () {
        expect(ruleUnexcused('media', 'kit-files', ruleKitFileViolations(mediaKitPaths())))->toBe([]);
    });

    it('binds every port of the library to an adapter in a provider', function () {
        $providers = implode("\n", array_map(
            fn (string $file): string => ruleCodeWithoutComments($file),
            array_values(array_filter(ruleSourceFiles('app', ['php']), fn (string $file): bool => str_ends_with($file, 'ServiceProvider.php'))),
        ));
        $violations = [];

        foreach (mediaSpec()['ports'] as $port) {
            $short = substr(strrchr($port, '\\') ?: '', 1);

            if (preg_match('/\b'.preg_quote($short, '/').'::class\s*=>/', $providers) !== 1) {
                $violations[] = ['subject' => $port, 'message' => 'is not bound to an adapter in any *ServiceProvider — bind it in app/Infra/Media/MediaServiceProvider.php and register that in bootstrap/providers.php'];
            }
        }

        expect(ruleUnexcused('media', 'binding', $violations))->toBe([]);
    });

    it('routes every controller of the library', function () {
        $routes = implode("\n", array_map(
            fn (string $file): string => ruleCodeWithoutComments(ltrim(substr($file, strlen(ruleProjectPath())), '/')),
            ruleGlob(ruleProjectPath(mediaSpec()['routes'])),
        ));
        $violations = [];

        foreach (mediaSpec()['controllers'] as $controller) {
            if (preg_match('/\b'.preg_quote($controller, '/').'::class\b/', $routes) !== 1) {
                $violations[] = ['subject' => "App\\Http\\Controllers\\{$controller}", 'message' => 'has no route — add the lines kit:install --with=media lists to routes/web.php, in the auth group'];
            }
        }

        expect(ruleUnexcused('media', 'routes', $violations))->toBe([]);
    });

    it('speaks every word of the library in every locale file', function () {
        $violations = [];

        foreach (ruleGlob(ruleProjectPath(mediaSpec()['lang_files'])) as $file) {
            $relative = ltrim(substr($file, strlen(ruleProjectPath())), '/');
            $words = json_decode((string) file_get_contents($file), true);
            $missing = array_keys(array_diff_key(mediaWords(), is_array($words) ? $words : []));

            if ($missing !== []) {
                $violations[] = ['subject' => $relative, 'message' => sprintf('lacks %d of the library\'s keys, such as %s — run `php artisan kit:install`, which adds them in English', count($missing), $missing[0])];
            }
        }

        expect(ruleUnexcused('media', 'words', $violations))->toBe([]);
    });
});
