<?php

use App\Models\MediaFolder;
use App\Models\MediaItem;
use Illuminate\Support\Str;

/**
 * PUT media-items/{media_item} (media-items.update), answered by MediaItemController::update().
 * `mediaLibraryManager()` and `mediaLibraryOutsider()` come from tests/Helpers/MediaLibrary.php.
 */
beforeEach(function () {
    $this->item = MediaItem::factory()->create();
});

function mediaItemUpdateActor(): object
{
    return test()->actingAs(mediaLibraryManager())->from(route('media-items.index'));
}

it('redirects a guest to the login page', function () {
    $this->put(route('media-items.update', $this->item), ['name' => 'x'])
        ->assertRedirect(route('login'));
});

it('forbids a user the policy refuses, before it validates', function () {
    $outsider = mediaLibraryOutsider();

    if ($outsider === null) {
        $this->markTestSkipped('The media policies refuse no signed-in user: mediaLibraryOutsider() is null');
    }

    $this->actingAs($outsider)
        ->put(route('media-items.update', $this->item), [])
        ->assertForbidden();
});

it('saves the name, alt text and folder, goes back and names it in the toast', function () {
    $folder = MediaFolder::factory()->create();

    mediaItemUpdateActor()
        ->put(route('media-items.update', $this->item), ['name' => ' Logo ', 'alt_text' => '', 'folder_id' => $folder->id])
        ->assertRedirect(route('media-items.index'))
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', __('media-items.updated', ['name' => 'Logo']));

    expect($this->item->fresh())
        ->name->toBe('Logo')
        ->alt_text->toBeNull()
        ->folder_id->toBe($folder->id);
});

it('answers a folder that is gone as not found', function () {
    mediaItemUpdateActor()
        ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->put(route('media-items.update', $this->item), ['name' => 'x', 'folder_id' => (string) Str::uuid()])
        ->assertRedirect(route('media-items.index'))
        ->assertInertiaFlash('toast.message', __('common.not_found'));

    expect($this->item->fresh()?->name)->not->toBe('x');
});

it('refuses each value the form must not take', function (array $payload, string $field) {
    mediaItemUpdateActor()
        ->put(route('media-items.update', $this->item), $payload)
        ->assertInvalid([$field]);
})->with([
    'no name' => [['name' => ''], 'name'],
    'a name too long' => [['name' => str_repeat('a', 151)], 'name'],
    'an alt text too long' => [['name' => 'x', 'alt_text' => str_repeat('a', 256)], 'alt_text'],
    'a folder that is not an id' => [['name' => 'x', 'folder_id' => 'x'], 'folder_id'],
]);
