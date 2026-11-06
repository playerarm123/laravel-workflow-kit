<?php

use App\Models\MediaFolder;
use App\Models\MediaItem;

/**
 * DELETE media-folders/{media_folder} (media-folders.destroy), answered by MediaFolderController::destroy().
 * `mediaLibraryManager()` and `mediaLibraryOutsider()` come from tests/Helpers/MediaLibrary.php.
 */
beforeEach(function () {
    $this->folder = MediaFolder::factory()->create(['name' => 'Team']);
});

it('redirects a guest to the login page', function () {
    $this->delete(route('media-folders.destroy', $this->folder))
        ->assertRedirect(route('login'));

    $this->assertModelExists($this->folder);
});

it('forbids a user the policy refuses', function () {
    $outsider = mediaLibraryOutsider();

    if ($outsider === null) {
        $this->markTestSkipped('The media policies refuse no signed-in user: mediaLibraryOutsider() is null');
    }

    $this->actingAs($outsider)
        ->delete(route('media-folders.destroy', $this->folder))
        ->assertForbidden();

    $this->assertModelExists($this->folder);
});

it('deletes the folder, keeps its images, goes back and names it in the toast', function () {
    $item = MediaItem::factory()->inFolder($this->folder)->create();

    $this->actingAs(mediaLibraryManager())
        ->from(route('media-items.index'))
        ->delete(route('media-folders.destroy', $this->folder))
        ->assertRedirect(route('media-items.index'))
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', __('media-folders.deleted', ['name' => 'Team']));

    $this->assertModelMissing($this->folder);
    expect($item->fresh()?->folder_id)->toBeNull();
});
