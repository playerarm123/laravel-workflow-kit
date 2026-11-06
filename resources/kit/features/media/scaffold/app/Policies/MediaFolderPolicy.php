<?php

namespace App\Policies;

use App\Models\MediaFolder;
use App\Models\User;

/**
 * Who may keep the image library's folders. The model names it with
 * #[UsePolicy(MediaFolderPolicy::class)]. The kit writes it once; narrow it to the project's roles,
 * together with MediaItemPolicy.
 */
class MediaFolderPolicy
{
    /**
     * Adding a folder, and the folder manager's add button.
     */
    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    /**
     * Renaming a folder.
     */
    public function update(User $user, MediaFolder $mediaFolder): bool
    {
        return $user->hasVerifiedEmail();
    }

    /**
     * Deleting a folder; its images stay in no folder.
     */
    public function delete(User $user, MediaFolder $mediaFolder): bool
    {
        return $user->hasVerifiedEmail();
    }
}
