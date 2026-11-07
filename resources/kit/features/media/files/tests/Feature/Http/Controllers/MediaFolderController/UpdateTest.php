<?php

use App\Models\MediaFolder;

/**
 * PUT media-folders/{media_folder} (media-folders.update), answered by MediaFolderController::update().
 * `mediaLibraryManager()` and `mediaLibraryOutsider()` come from tests/Helpers/MediaLibrary.php.
 */
beforeEach(function () {
    $this->folder = MediaFolder::factory()->create(['name' => 'Team']);
});

function mediaFolderUpdateActor(): object
{
    return test()->actingAs(mediaLibraryManager())->from(route('media-items.index'));
}

it('redirects a guest to the login page', function () {
    $this->put(route('media-folders.update', $this->folder), ['name' => 'x'])
        ->assertRedirect(route('login'));
});

it('forbids a user the policy refuses, before it validates', function () {
    $outsider = mediaLibraryOutsider();

    if ($outsider === null) {
        $this->markTestSkipped('The media policies refuse no signed-in user: mediaLibraryOutsider() is null');
    }

    $this->actingAs($outsider)
        ->put(route('media-folders.update', $this->folder), [])
        ->assertForbidden();
});

it('renames the folder, goes back and names it in the toast', function () {
    mediaFolderUpdateActor()
        ->put(route('media-folders.update', $this->folder), ['name' => 'Staff'])
        ->assertRedirect(route('media-items.index'))
        ->assertInertiaFlash('toast.message', __('media-folders.updated', ['name' => 'Staff']));

    expect($this->folder->fresh()?->name)->toBe('Staff');
});

it('refuses a name another folder has, under the field', function () {
    MediaFolder::factory()->create(['name' => 'Logos']);

    mediaFolderUpdateActor()
        ->put(route('media-folders.update', $this->folder), ['name' => 'Logos'])
        ->assertSessionHasErrors(['name' => __('media-folders.name_taken')]);
});

it('refuses each value the form must not take', function (array $payload) {
    mediaFolderUpdateActor()
        ->put(route('media-folders.update', $this->folder), $payload)
        ->assertInvalid(['name']);
})->with([
    'no name' => [['name' => '']],
    'a name too long' => [['name' => str_repeat('a', 61)]],
]);
