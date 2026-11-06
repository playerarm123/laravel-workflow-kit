<?php

use App\Models\MediaFolder;
use App\Models\MediaItem;

/**
 * POST media-items/move (media-items.bulk-move), answered by MediaItemBulkMoveController.
 * `mediaLibraryManager()` and `mediaLibraryOutsider()` come from tests/Helpers/MediaLibrary.php.
 */
beforeEach(function () {
    $this->folder = MediaFolder::factory()->create();
});

function mediaItemBulkMoveActor(): object
{
    return test()->actingAs(mediaLibraryManager())->from(route('media-items.index'));
}

it('redirects a guest to the login page', function () {
    $this->post(route('media-items.bulk-move'), ['ids' => []])
        ->assertRedirect(route('login'));
});

it('forbids a user the policy refuses, before it validates', function () {
    $outsider = mediaLibraryOutsider();

    if ($outsider === null) {
        $this->markTestSkipped('The media policies refuse no signed-in user: mediaLibraryOutsider() is null');
    }

    $this->actingAs($outsider)
        ->post(route('media-items.bulk-move'), [])
        ->assertForbidden();
});

it('moves every image and says how many', function () {
    $items = MediaItem::factory()->count(2)->create();

    mediaItemBulkMoveActor()
        ->post(route('media-items.bulk-move'), ['ids' => $items->pluck('id')->all(), 'folder_id' => $this->folder->id])
        ->assertRedirect(route('media-items.index'))
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', __('media-items.bulk_move_succeeded', ['changed' => 2]));

    expect(MediaItem::query()->where('folder_id', $this->folder->id)->count())->toBe(2);
});

it('names both numbers when some were there already', function () {
    $loose = MediaItem::factory()->create();
    $there = MediaItem::factory()->inFolder($this->folder)->create();

    mediaItemBulkMoveActor()
        ->post(route('media-items.bulk-move'), ['ids' => [$loose->id, $there->id], 'folder_id' => $this->folder->id])
        ->assertInertiaFlash('toast.message', __('media-items.bulk_move_partial', ['changed' => 1, 'skipped' => 1]));
});

it('answers an error when nothing moved', function () {
    $there = MediaItem::factory()->inFolder($this->folder)->create();

    mediaItemBulkMoveActor()
        ->post(route('media-items.bulk-move'), ['ids' => [$there->id], 'folder_id' => $this->folder->id])
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', __('media-items.bulk_move_unchanged', ['count' => 1]));
});

it('refuses each value the form must not take', function (Closure $payload, string $field) {
    mediaItemBulkMoveActor()
        ->post(route('media-items.bulk-move'), $payload())
        ->assertInvalid([$field]);
})->with([
    'no images' => [fn () => ['ids' => []], 'ids'],
    'an id that is not an image' => [fn () => ['ids' => ['01a15500-0000-7000-8000-000000000999']], 'ids.0'],
    'a folder that is not an id' => [fn () => ['ids' => [MediaItem::factory()->create()->id], 'folder_id' => 'x'], 'folder_id'],
]);
