<?php

use App\Application\Media\MediaQuota;
use App\Application\Media\MediaQuotaExceededException;
use App\Application\Media\MediaQuotaUsage;
use App\Models\MediaFolder;
use App\Models\MediaItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * POST media-items (media-items.store), answered by MediaItemController::store().
 * `mediaLibraryManager()` and `mediaLibraryOutsider()` come from tests/Helpers/MediaLibrary.php.
 */
beforeEach(function () {
    Storage::fake('public');
    config(['media.disk' => 'public']);
});

function mediaItemStoreActor(): object
{
    return test()->actingAs(mediaLibraryManager())->from(route('media-items.index'));
}

it('redirects a guest to the login page', function () {
    $this->post(route('media-items.store'), ['images' => [UploadedFile::fake()->image('a.jpg')]])
        ->assertRedirect(route('login'));
});

it('forbids a user the policy refuses, before it validates', function () {
    $outsider = mediaLibraryOutsider();

    if ($outsider === null) {
        $this->markTestSkipped('The media policies refuse no signed-in user: mediaLibraryOutsider() is null');
    }

    $this->actingAs($outsider)
        ->post(route('media-items.store'), [])
        ->assertForbidden();
});

it('uploads the images into the folder, goes back and counts them in the toast', function () {
    $folder = MediaFolder::factory()->create();

    mediaItemStoreActor()
        ->post(route('media-items.store'), ['images' => [UploadedFile::fake()->image('a.jpg', 40, 40), UploadedFile::fake()->image('b.png', 40, 40)], 'folder_id' => $folder->id])
        ->assertRedirect(route('media-items.index'))
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('toast.message', __('media-items.uploaded', ['count' => 2]));

    expect(MediaItem::query()->where('folder_id', $folder->id)->count())->toBe(2);
});

it('answers JSON with the new ids for the picker', function () {
    $this->actingAs(mediaLibraryManager())
        ->postJson(route('media-items.store'), ['images' => [UploadedFile::fake()->image('a.jpg', 40, 40)]])
        ->assertCreated()
        ->assertJsonCount(1, 'ids');
});

it('refuses an upload past the quota with a toast, or a 409 for the picker', function () {
    app()->instance(MediaQuota::class, new class implements MediaQuota
    {
        public function assertRoomFor(int $addedBytes): void
        {
            throw MediaQuotaExceededException::exceeded(1_048_576, $addedBytes, 1_048_576);
        }

        public function usage(): MediaQuotaUsage
        {
            return new MediaQuotaUsage(1_048_576, 1_048_576);
        }
    });
    $payload = fn () => ['images' => [UploadedFile::fake()->image('a.jpg', 40, 40)]];

    mediaItemStoreActor()
        ->post(route('media-items.store'), $payload())
        ->assertRedirect(route('media-items.index'))
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', __('media-items.quota_exceeded', ['quota' => 1]));

    $this->postJson(route('media-items.store'), $payload())
        ->assertStatus(409)
        ->assertJsonPath('code', 'media_quota_exceeded');

    expect(MediaItem::query()->count())->toBe(0);
});

it('refuses each value the form must not take', function (Closure $payload, string $field) {
    mediaItemStoreActor()
        ->post(route('media-items.store'), $payload())
        ->assertInvalid([$field]);

    expect(MediaItem::query()->count())->toBe(0);
})->with([
    'no image' => [fn () => ['images' => []], 'images'],
    'too many images' => [fn () => ['images' => array_map(fn (int $i) => UploadedFile::fake()->image("{$i}.jpg", 10, 10), range(1, (int) config('media.max_files') + 1))], 'images'],
    'a file that is not an image' => [fn () => ['images' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]], 'images.0'],
    'an image too heavy' => [fn () => ['images' => [UploadedFile::fake()->image('a.jpg', 10, 10)->size((int) config('media.max_kb') + 1)]], 'images.0'],
    'an image too large' => [fn () => ['images' => [UploadedFile::fake()->image('a.jpg', (int) config('media.max_dimension') + 1, 10)]], 'images.0'],
    'a folder that is not an id' => [fn () => ['images' => [UploadedFile::fake()->image('a.jpg', 10, 10)], 'folder_id' => 'x'], 'folder_id'],
]);
