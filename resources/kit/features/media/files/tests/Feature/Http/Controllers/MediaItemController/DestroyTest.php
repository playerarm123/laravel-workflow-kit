<?php

use App\Models\MediaItem;
use App\Models\MediaUsage;
use Illuminate\Support\Facades\Storage;

/**
 * DELETE media-items/{media_item} (media-items.destroy), answered by MediaItemController::destroy().
 * `mediaLibraryManager()` and `mediaLibraryOutsider()` come from tests/Helpers/MediaLibrary.php.
 */
beforeEach(function () {
    Storage::fake('public');
    $this->item = MediaItem::factory()->create(['name' => 'Logo']);
});

function mediaItemDestroyActor(): object
{
    return test()->actingAs(mediaLibraryManager())->from(route('media-items.index'));
}

it('redirects a guest to the login page', function () {
    $this->delete(route('media-items.destroy', $this->item))
        ->assertRedirect(route('login'));

    $this->assertModelExists($this->item);
});

it('forbids a user the policy refuses', function () {
    $outsider = mediaLibraryOutsider();

    if ($outsider === null) {
        $this->markTestSkipped('The media policies refuse no signed-in user: mediaLibraryOutsider() is null');
    }

    $this->actingAs($outsider)
        ->delete(route('media-items.destroy', $this->item))
        ->assertForbidden();

    $this->assertModelExists($this->item);
});

it('deletes the image, goes back and names it in the toast', function () {
    mediaItemDestroyActor()
        ->delete(route('media-items.destroy', $this->item))
        ->assertRedirect(route('media-items.index'))
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', __('media-items.deleted', ['name' => 'Logo']));

    $this->assertModelMissing($this->item);
});

it('keeps an image something uses and says what uses it', function () {
    MediaUsage::factory()->create(['media_item_id' => $this->item->id, 'label' => 'Jane Doe', 'created_at' => '2026-10-01 09:00:00']);
    MediaUsage::factory()->create(['media_item_id' => $this->item->id, 'label' => 'John Roe', 'created_at' => '2026-10-02 09:00:00']);

    mediaItemDestroyActor()
        ->delete(route('media-items.destroy', $this->item))
        ->assertRedirect(route('media-items.index'))
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', __('media-items.in_use', ['name' => 'Logo', 'usages' => 'Jane Doe, John Roe']));

    $this->assertModelExists($this->item);
});
