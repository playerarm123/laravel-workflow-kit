<?php

namespace App\Http\Controllers;

use App\Application\Media\MediaQuotaExceededException;
use App\Application\Media\UseCases\ChangeMediaItemInfos\ChangeMediaItemInfosHandler;
use App\Application\Media\UseCases\DeleteMediaItemHandler;
use App\Application\Media\UseCases\ListMediaItems\ListMediaItemsHandler;
use App\Application\Media\UseCases\ShowMediaFoldersHandler;
use App\Application\Media\UseCases\ShowMediaUsageHandler;
use App\Application\Media\UseCases\UploadMediaItems\UploadMediaItemsHandler;
use App\Domain\Media\MediaItem\Exceptions\MediaItemInUseException;
use App\Domain\Media\MediaItem\ValueObjects\MediaUsage;
use App\Http\ApiError;
use App\Http\FlashToast;
use App\Http\Requests\MediaItem\IndexMediaItemRequest;
use App\Http\Requests\MediaItem\StoreMediaItemRequest;
use App\Http\Requests\MediaItem\UpdateMediaItemRequest;
use App\Models\MediaFolder;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The image library. Every write answers with back(), so the grid keeps its filters. The list
 * and uploads also answer JSON, for the picker that works from inside another page's form.
 */
class MediaItemController extends Controller
{
    /**
     * The library page. The picker, a dialog inside another page's form, asks the same url for
     * JSON: its page of images and the folders to filter them by, without the page's own props.
     */
    public function index(
        IndexMediaItemRequest $request,
        ListMediaItemsHandler $listMediaItems,
        ShowMediaFoldersHandler $showMediaFolders,
        ShowMediaUsageHandler $showMediaUsage,
    ): Response|JsonResponse {
        $result = $listMediaItems($request->toCommand());

        if ($request->wantsJson()) {
            return response()->json([
                'mediaItems' => $result->mediaItems,
                'folders' => $showMediaFolders(),
                'limits' => $this->limits(),
                'can' => ['create' => Gate::allows('create', MediaItem::class)],
            ]);
        }

        return Inertia::render('media-items/index', [
            'mediaItems' => Inertia::scroll($result->mediaItems),
            'filters' => $result->filters,
            'storage' => $showMediaUsage()->toArray(),
            'folders' => $showMediaFolders(),
            'limits' => $this->limits(),
            'can' => [
                'create' => Gate::allows('create', MediaItem::class),
                'update' => Gate::allows('updateAny', MediaItem::class),
                'move' => Gate::allows('moveAny', MediaItem::class),
                'delete' => Gate::allows('deleteAny', MediaItem::class),
                'createMediaFolder' => Gate::allows('create', MediaFolder::class),
            ],
        ]);
    }

    public function store(StoreMediaItemRequest $request, UploadMediaItemsHandler $uploadMediaItems): RedirectResponse|JsonResponse
    {
        try {
            $ids = $uploadMediaItems($request->toCommand())->ids;
        } catch (MediaQuotaExceededException $e) {
            $message = __('media-items.quota_exceeded', ['quota' => (int) ceil($e->limitBytes() / 1_048_576)]);

            return $request->wantsJson() ? ApiError::refused('media_quota_exceeded', $message) : $this->refused($message);
        }

        if ($request->wantsJson()) {
            return response()->json(['ids' => $ids], 201);
        }

        return Inertia::flash(FlashToast::KEY, FlashToast::success(__('media-items.uploaded', ['count' => count($ids)])))->back();
    }

    public function update(
        UpdateMediaItemRequest $request,
        MediaItem $mediaItem,
        ChangeMediaItemInfosHandler $changeMediaItemInfos,
    ): RedirectResponse {
        $command = $request->toCommand();

        $changeMediaItemInfos($command);

        return Inertia::flash(FlashToast::KEY, FlashToast::success(__('media-items.updated', ['name' => $command->name])))->back();
    }

    /**
     * An image something still uses stays, and the toast says what uses it.
     */
    public function destroy(MediaItem $mediaItem, DeleteMediaItemHandler $deleteMediaItem): RedirectResponse
    {
        Gate::authorize('delete', $mediaItem);

        $name = $mediaItem->name;

        try {
            $deleteMediaItem($mediaItem->id);
        } catch (MediaItemInUseException $e) {
            $usages = implode(', ', array_map(fn (MediaUsage $usage): string => $usage->label(), $e->usages()));

            return Inertia::flash(FlashToast::KEY, FlashToast::error(__('media-items.in_use', ['name' => $name, 'usages' => $usages])))->back();
        }

        return Inertia::flash(FlashToast::KEY, FlashToast::success(__('media-items.deleted', ['name' => $name])))->back();
    }

    /**
     * What an upload may hold, from config/media.php, so the page says it before it posts.
     *
     * @return array{max_files: int, max_kb: int, extensions: list<string>}
     */
    private function limits(): array
    {
        return [
            'max_files' => (int) config('media.max_files'),
            'max_kb' => (int) config('media.max_kb'),
            'extensions' => array_values(config('media.extensions')),
        ];
    }

    private function refused(string $message): RedirectResponse
    {
        return Inertia::flash(FlashToast::KEY, FlashToast::error($message))->back();
    }
}
