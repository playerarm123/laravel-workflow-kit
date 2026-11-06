<?php

namespace App\Http\Controllers;

use App\Application\Media\UseCases\MoveMediaItems\MoveMediaItemsHandler;
use App\Http\FlashToast;
use App\Http\Requests\MediaItem\MediaItemBulkMoveRequest;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\RedirectResponse;

class MediaItemBulkMoveController extends Controller
{
    /**
     * Authorized and validated by MediaItemBulkMoveRequest. The handler skips each row it may no longer
     * change and returns how many it did change, so the toast names both numbers.
     */
    public function __invoke(
        MediaItemBulkMoveRequest $request,
        MoveMediaItemsHandler $moveMediaItems,
    ): RedirectResponse {
        $command = $request->toCommand();
        $changed = $moveMediaItems($command);
        $skipped = count($command->ids) - $changed;

        if ($changed === 0) {
            return Inertia::flash(
                FlashToast::KEY,
                FlashToast::error(__('media-items.bulk_move_unchanged', ['count' => $skipped])),
            )->back();
        }

        $message = $skipped > 0
            ? __('media-items.bulk_move_partial', ['changed' => $changed, 'skipped' => $skipped])
            : __('media-items.bulk_move_succeeded', ['changed' => $changed]);

        return Inertia::flash(FlashToast::KEY, FlashToast::success($message))->back();
    }
}
