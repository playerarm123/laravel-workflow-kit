<?php

namespace App\Application\Media;

use Illuminate\Contracts\Support\Arrayable;

/**
 * How much of its limit the image library uses, originals and thumbnails together.
 *
 * @implements Arrayable<string, mixed>
 */
final class MediaQuotaUsage implements Arrayable
{
    /**
     * @param  int|null  $limitBytes  null means no limit
     */
    public function __construct(
        public readonly int $usedBytes,
        public readonly ?int $limitBytes,
    ) {}

    /**
     * @return array{used_bytes: int, limit_bytes: int|null}
     */
    public function toArray(): array
    {
        return [
            'used_bytes' => $this->usedBytes,
            'limit_bytes' => $this->limitBytes,
        ];
    }
}
