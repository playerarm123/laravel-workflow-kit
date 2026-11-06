<?php

namespace App\Application\Media;

use Illuminate\Contracts\Support\Arrayable;

/**
 * One folder of the image library, as the folder filter, the upload and move dialogs and the
 * folder manager list it.
 *
 * @implements Arrayable<string, mixed>
 */
final class MediaFolderOption implements Arrayable
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $itemCount,
    ) {}

    /**
     * @return array{id: string, name: string, item_count: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'item_count' => $this->itemCount,
        ];
    }
}
