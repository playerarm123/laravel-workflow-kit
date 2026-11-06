<?php

use App\Models\MediaFolder;
use App\Models\MediaItem;
use App\Models\MediaUsage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The image library (resources/js/pages/media-items/index.tsx) in a real browser, as a user who
 * keeps it sees it: `mediaLibraryManager()` comes from tests/Helpers/MediaLibrary.php. The images go on the real public disk under names of this test's own, so the
 * page loads them as a deployed app does, and afterEach removes them. The disk's url is made
 * relative, so the browser loads them from the test's own server rather than APP_URL, where
 * nothing listens in CI. Run `npm run build` first: the suite runs on public/build.
 */
/**
 * A library image whose files really exist on the public disk.
 *
 * @param  array<string, mixed>  $attributes
 */
function mediaItemsIndexImage(array $attributes = []): MediaItem
{
    $disk = Storage::disk('public');
    $key = 'media/browser-test-'.fake()->uuid();
    $disk->putFileAs('', UploadedFile::fake()->image('a.png', 40, 40), "{$key}.png");
    $disk->putFileAs('', UploadedFile::fake()->image('a.png', 40, 40), "{$key}_thumb.png");
    test()->mediaItemsIndexPaths = [...test()->mediaItemsIndexPaths, "{$key}.png", "{$key}_thumb.png"];

    return MediaItem::factory()->create([
        'disk' => 'public',
        'path' => "{$key}.png",
        'thumbnail_path' => "{$key}_thumb.png",
        'mime_type' => 'image/png',
        ...$attributes,
    ]);
}

beforeEach(function () {
    config(['media.disk' => 'public', 'filesystems.disks.public.url' => '/storage']);
    Storage::forgetDisk('public');
    $this->mediaItemsIndexPaths = [];
    $this->actingAs(mediaLibraryManager());
});

afterEach(function () {
    Storage::disk('public')->delete($this->mediaItemsIndexPaths);
});

it('renders the grid and the storage it uses without javascript errors', function () {
    mediaItemsIndexImage(['name' => 'Company logo', 'size_bytes' => 2_000, 'thumbnail_size_bytes' => 48]);

    visit(route('media-items.index'))
        ->assertSee('Company logo')
        ->assertSeeIn('[data-test="media-storage"]', '2.0 KB')
        ->assertScript('document.querySelector(\'[data-test="media-item-card"] img\').naturalWidth', 40)
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

it('shows the folder, the size and where an image is used', function () {
    $folder = MediaFolder::factory()->create(['name' => 'Team']);
    $item = mediaItemsIndexImage(['name' => 'Jane', 'folder_id' => $folder->id, 'size_bytes' => 1_024, 'thumbnail_size_bytes' => 1_024]);
    MediaUsage::factory()->create(['media_item_id' => $item->id, 'usable_type' => 'profile', 'label' => 'Jane Doe']);

    visit(route('media-items.index'))
        ->assertSeeIn('[data-test="media-item-card"]', 'Team')
        ->assertSeeIn('[data-test="media-item-card"]', '2.0 KB')
        ->assertSeeIn('[data-test="media-item-card"]', __('media-items.used_in', ['count' => 1]))
        ->click(sprintf('[data-test="media-item-card"] button[aria-label="%s"]', __('media-items.preview')))
        ->assertSeeIn('[data-test="media-item-usages"]', 'Jane Doe')
        ->assertNoJavaScriptErrors();
});

it('narrows the grid through the toolbar and keeps the query on the url', function () {
    mediaItemsIndexImage(['name' => 'Logo']);
    mediaItemsIndexImage(['name' => 'Office']);

    visit(route('media-items.index', ['usage' => 'unused']))
        ->fill(sprintf('input[aria-label="%s"]', __('common.search')), 'Logo')
        ->wait(1)
        ->assertQueryStringHas('search', 'Logo')
        ->assertQueryStringHas('usage', 'unused')
        ->assertCount('[data-test="media-item-card"]', 1)
        ->assertNoJavaScriptErrors();
});

it('loads the next page of cards as the user scrolls', function () {
    foreach (range(1, 27) as $number) {
        MediaItem::factory()->create(['created_at' => now()->subMinutes($number)]);
    }

    $page = visit(route('media-items.index'))->assertCount('[data-test="media-item-card"]', 25);
    $page->script('window.scrollTo(0, document.body.scrollHeight)');

    $page->wait(1)
        ->assertCount('[data-test="media-item-card"]', 27)
        ->assertNoJavaScriptErrors();
});

/**
 * Pest's in-process server reads a multipart name such as `images[0]` flat where PHP nests it, so
 * the post itself is proven by MediaItemController/StoreTest; this case stops at what only the
 * browser does: queueing the file it was given.
 */
it('queues a picked image in the upload dialog with its preview', function () {
    $image = UploadedFile::fake()->image('waiting-room.jpg', 300, 200);

    visit(route('media-items.index'))
        ->click(sprintf('button:has-text("%s")', __('media-items.upload')))
        ->attach('input[data-test="media-file-input"]', $image->getPathname())
        ->assertVisible('[role="dialog"] li img')
        ->assertSeeIn('[role="dialog"] button[type="submit"]', __('media-items.upload_submit', ['count' => 1]))
        ->assertNoJavaScriptErrors();
});

it('shows the selection bar once an image is ticked', function () {
    mediaItemsIndexImage();

    visit(route('media-items.index'))
        ->click('[data-test="media-item-card"] button[role="checkbox"]')
        ->assertSeeIn('[data-test="media-selection-bar"]', __('media-items.selected', ['count' => 1]))
        ->assertNoJavaScriptErrors();
});

it('shows the empty state when there is nothing yet', function () {
    visit(route('media-items.index'))
        ->assertSee(__('media-items.empty_title'))
        ->assertNoJavaScriptErrors();
});

it('shows the no results state once filtered to nothing', function () {
    mediaItemsIndexImage();

    visit(route('media-items.index', ['search' => 'nothing-like-it']))
        ->assertSee(__('media-items.no_results_title'))
        ->assertNoJavaScriptErrors();
});
