<?php

namespace App\Policies;

use App\Models\MediaItem;
use App\Models\User;

/**
 * Who may see and change the image library. The model names it with
 * #[UsePolicy(MediaItemPolicy::class)]. The kit writes it once; narrow it to the project's roles.
 * mediaLibraryManager() and mediaLibraryOutsider() in tests/Helpers/MediaLibrary.php change with
 * it.
 */
class MediaItemPolicy
{
    /**
     * The library page and the picker.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    /**
     * Uploading images.
     */
    public function create(User $user): bool
    {
        return $this->managesLibrary($user);
    }

    /**
     * Renaming an image, its alt text and its folder.
     */
    public function update(User $user, MediaItem $mediaItem): bool
    {
        return $this->managesLibrary($user);
    }

    /**
     * The edit button on every card, before it has a card.
     */
    public function updateAny(User $user): bool
    {
        return $this->managesLibrary($user);
    }

    /**
     * Moving the selected images to another folder. A bulk move has no single card to ask about.
     */
    public function moveAny(User $user): bool
    {
        return $this->managesLibrary($user);
    }

    /**
     * Deleting an image nothing uses.
     */
    public function delete(User $user, MediaItem $mediaItem): bool
    {
        return $this->managesLibrary($user);
    }

    /**
     * The delete button on every card, before it has a card.
     */
    public function deleteAny(User $user): bool
    {
        return $this->managesLibrary($user);
    }

    /**
     * Every user who verified their email, until the project names who keeps the library.
     */
    private function managesLibrary(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }
}
