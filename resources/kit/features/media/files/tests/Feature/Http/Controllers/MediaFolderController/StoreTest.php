<?php

use App\Models\MediaFolder;

/**
 * POST media-folders (media-folders.store), answered by MediaFolderController::store().
 * `mediaLibraryManager()` and `mediaLibraryOutsider()` come from tests/Helpers/MediaLibrary.php.
 */
function mediaFolderStoreActor(): object
{
    return test()->actingAs(mediaLibraryManager())->from(route('media-items.index'));
}

it('redirects a guest to the login page', function () {
    $this->post(route('media-folders.store'), ['name' => 'x'])
        ->assertRedirect(route('login'));
});

it('forbids a user the policy refuses, before it validates', function () {
    $outsider = mediaLibraryOutsider();

    if ($outsider === null) {
        $this->markTestSkipped('The media policies refuse no signed-in user: mediaLibraryOutsider() is null');
    }

    $this->actingAs($outsider)
        ->post(route('media-folders.store'), [])
        ->assertForbidden();
});

it('adds the folder, goes back and names it in the toast', function () {
    mediaFolderStoreActor()
        ->post(route('media-folders.store'), ['name' => ' Team '])
        ->assertRedirect(route('media-items.index'))
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', __('media-folders.created', ['name' => 'Team']));

    expect(MediaFolder::query()->sole()->name)->toBe('Team');
});

it('refuses a name another folder has, under the field', function () {
    MediaFolder::factory()->create(['name' => 'Team']);

    mediaFolderStoreActor()
        ->post(route('media-folders.store'), ['name' => 'team'])
        ->assertSessionHasErrors(['name' => __('media-folders.name_taken')])
        ->assertInertiaFlash('toast.type', 'error');
});

it('refuses each value the form must not take', function (array $payload) {
    mediaFolderStoreActor()
        ->post(route('media-folders.store'), $payload)
        ->assertInvalid(['name']);
})->with([
    'no name' => [['name' => '']],
    'a name too long' => [['name' => str_repeat('a', 61)]],
]);
