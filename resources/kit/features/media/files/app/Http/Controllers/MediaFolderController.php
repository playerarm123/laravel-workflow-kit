<?php

namespace App\Http\Controllers;

use App\Application\Media\MediaFolderNameTakenException;
use App\Application\Media\UseCases\CreateMediaFolder\CreateMediaFolderHandler;
use App\Application\Media\UseCases\DeleteMediaFolderHandler;
use App\Application\Media\UseCases\RenameMediaFolder\RenameMediaFolderHandler;
use App\Http\FlashToast;
use App\Http\Requests\MediaFolder\StoreMediaFolderRequest;
use App\Http\Requests\MediaFolder\UpdateMediaFolderRequest;
use App\Models\MediaFolder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The folders of the image library, kept from a dialog on the library page, so every
 * answer goes back() to it.
 */
class MediaFolderController extends Controller
{
    public function store(StoreMediaFolderRequest $request, CreateMediaFolderHandler $createMediaFolder): RedirectResponse
    {
        $command = $request->toCommand();

        try {
            $createMediaFolder($command);
        } catch (MediaFolderNameTakenException) {
            return $this->nameTaken();
        }

        return Inertia::flash(FlashToast::KEY, FlashToast::success(__('media-folders.created', ['name' => $command->name])))->back();
    }

    public function update(
        UpdateMediaFolderRequest $request,
        MediaFolder $mediaFolder,
        RenameMediaFolderHandler $renameMediaFolder,
    ): RedirectResponse {
        $command = $request->toCommand();

        try {
            $renameMediaFolder($command);
        } catch (MediaFolderNameTakenException) {
            return $this->nameTaken();
        }

        return Inertia::flash(FlashToast::KEY, FlashToast::success(__('media-folders.updated', ['name' => $command->name])))->back();
    }

    /**
     * The folder's images stay in the library, in no folder.
     */
    public function destroy(MediaFolder $mediaFolder, DeleteMediaFolderHandler $deleteMediaFolder): RedirectResponse
    {
        Gate::authorize('delete', $mediaFolder);

        $name = $mediaFolder->name;

        $deleteMediaFolder($mediaFolder->id);

        return Inertia::flash(FlashToast::KEY, FlashToast::success(__('media-folders.deleted', ['name' => $name])))->back();
    }

    private function nameTaken(): RedirectResponse
    {
        Inertia::flash(FlashToast::KEY, FlashToast::error(__('media-folders.name_taken')));

        return back()->withErrors(['name' => __('media-folders.name_taken')]);
    }
}
