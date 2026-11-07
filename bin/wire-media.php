<?php

/**
 * The CI's hands for the media feature: in the project of the current directory, it makes the
 * edits the feature's feature.json asks a person to make once, on a project fresh from kit:setup.
 * The `starter-kit` job runs it after `kit:install --with=media`; the shell steps around it do the
 * rest (storage:link, kit:import, kit:apply, the build). It fails loudly on a file it cannot read
 * the way it expects, rather than leaving a half-wired project to fail later.
 */
function wireMediaEdit(string $path, callable $edit): void
{
    $code = (string) file_get_contents($path);
    $edited = $edit($code);

    if ($edited === $code) {
        fwrite(STDERR, "wire-media: could not wire {$path}\n");
        exit(1);
    }

    file_put_contents($path, $edited);
}

wireMediaEdit('bootstrap/providers.php', fn (string $code): string => str_replace(
    ['use App\Infra\Audit\AuditServiceProvider;', "    AuditServiceProvider::class,\n"],
    ["use App\\Infra\\Audit\\AuditServiceProvider;\nuse App\\Infra\\Media\\MediaServiceProvider;", "    AuditServiceProvider::class,\n    MediaServiceProvider::class,\n"],
    $code,
));

wireMediaEdit('routes/web.php', fn (string $code): string => str_replace(
    ['use App\Http\Controllers\AuditEntryController;', "    Route::resource('audit-entries', AuditEntryController::class)->only(['index']);\n"],
    [
        "use App\\Http\\Controllers\\AuditEntryController;\nuse App\\Http\\Controllers\\MediaFolderController;\nuse App\\Http\\Controllers\\MediaItemBulkMoveController;\nuse App\\Http\\Controllers\\MediaItemController;",
        "    Route::resource('audit-entries', AuditEntryController::class)->only(['index']);\n"
        ."    Route::resource('media-folders', MediaFolderController::class)->only(['store', 'update', 'destroy']);\n"
        ."    Route::post('media-items/move', MediaItemBulkMoveController::class)->name('media-items.bulk-move');\n"
        ."    Route::resource('media-items', MediaItemController::class)->only(['index', 'store', 'update', 'destroy']);\n",
    ],
    $code,
));

$manifest = '.kit/structure/http/MediaItem.json';

wireMediaEdit($manifest, function (string $json): string {
    $resource = json_decode($json, true);

    if (! is_array($resource)) {
        return $json;
    }

    $resource['menu'] = ['title' => 'media-items.title', 'icon' => 'images', 'description' => 'media-items.menu_description'];

    return json_encode($resource, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
});
