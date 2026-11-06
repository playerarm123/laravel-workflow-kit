<?php

namespace App\Application\Media;

/**
 * How much the image library may hold. An adapter in App\Infra implements it and the project's
 * MediaServiceProvider binds the two (layers.md). The kit binds UnlimitedMediaQuota, which sets no
 * limit; a project with plans or a storage budget binds its own.
 */
interface MediaQuota
{
    /**
     * Refuses $addedBytes more when they would take the library past its limit. The upload calls
     * it inside its transaction, after the files are written, because only the resized copies say
     * how much they weigh.
     *
     * @throws MediaQuotaExceededException
     */
    public function assertRoomFor(int $addedBytes): void;

    /**
     * What the library uses now and what it may use, for the page to show.
     */
    public function usage(): MediaQuotaUsage;
}
