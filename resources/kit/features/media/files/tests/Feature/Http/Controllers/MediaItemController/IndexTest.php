<?php

use App\Models\MediaFolder;
use App\Models\MediaItem;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * GET media-items (media-items.index), the image library.
 * `mediaLibraryManager()` and `mediaLibraryOutsider()` come from tests/Helpers/MediaLibrary.php.
 */
beforeEach(function () {
    MediaFolder::factory()->create(['name' => 'Team']);
    MediaItem::factory()->create(['size_bytes' => 1_000, 'thumbnail_size_bytes' => 24]);
});

it('redirects a guest to the login page', function () {
    $this->get(route('media-items.index'))
        ->assertRedirect(route('login'));
});

it('forbids a user the policy refuses', function () {
    $outsider = mediaLibraryOutsider();

    if ($outsider === null) {
        $this->markTestSkipped('The media policies refuse no signed-in user: mediaLibraryOutsider() is null');
    }

    $this->actingAs($outsider)
        ->get(route('media-items.index'))
        ->assertForbidden();
});

it('shows the images, the storage they use, the folders and limits, and says what the user may do', function () {
    $this->actingAs(mediaLibraryManager())
        ->get(route('media-items.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('media-items/index')
            ->has('mediaItems.data', 1)
            ->where('filters', ['search' => '', 'folder' => null, 'usage' => null, 'created_from' => null, 'created_to' => null])
            ->where('storage', ['used_bytes' => 1_024, 'limit_bytes' => null])
            ->has('folders', 1)
            ->where('folders.0.name', 'Team')
            ->where('limits.max_files', config('media.max_files'))
            ->where('can', ['create' => true, 'update' => true, 'move' => true, 'delete' => true, 'createMediaFolder' => true]));
});

it('passes the filters on to the list', function () {
    $this->actingAs(mediaLibraryManager())
        ->get(route('media-items.index', ['folder' => 'none', 'usage' => 'in_use', 'search' => 'x']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('mediaItems.data', 0)
            ->where('filters.folder', 'none')
            ->where('filters.usage', 'in_use')
            ->where('filters.search', 'x'));
});

it('answers the picker JSON: the images in a folder, the folders and whether the user may upload', function () {
    $folder = MediaFolder::factory()->create();
    $item = MediaItem::factory()->inFolder($folder)->create();

    $this->actingAs(mediaLibraryManager())
        ->getJson(route('media-items.index', ['folder' => $folder->id]))
        ->assertOk()
        ->assertJsonPath('mediaItems.total', 1)
        ->assertJsonPath('mediaItems.data.0.id', $item->id)
        ->assertJsonPath('folders.0.id', $folder->id)
        ->assertJsonPath('limits.max_files', config('media.max_files'))
        ->assertJsonPath('can', ['create' => true])
        ->assertJsonMissingPath('storage');
});

it('forbids the picker JSON to a user the policy refuses', function () {
    $outsider = mediaLibraryOutsider();

    if ($outsider === null) {
        $this->markTestSkipped('The media policies refuse no signed-in user: mediaLibraryOutsider() is null');
    }

    $this->actingAs($outsider)
        ->getJson(route('media-items.index'))
        ->assertForbidden();
});
