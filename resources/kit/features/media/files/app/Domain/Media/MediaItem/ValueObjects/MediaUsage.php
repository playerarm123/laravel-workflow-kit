<?php

namespace App\Domain\Media\MediaItem\ValueObjects;

use App\Domain\Media\MediaItem\Exceptions\InvalidMediaUsageException;

/**
 * One place an image is used: the kind of thing using it (a logical key such as `profile`, never
 * a class name), its id, and a label a person can read, such as the profile's name.
 * The aggregate that picks an image registers and withdraws its usage; the library only reads
 * them to refuse deleting an image still in use.
 */
final class MediaUsage
{
    /**
     * @throws InvalidMediaUsageException
     */
    private function __construct(
        private readonly string $usableType,
        private readonly string $usableId,
        private readonly string $label,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $usableType) !== 1) {
            throw InvalidMediaUsageException::malformedUsableType($usableType);
        }

        if (trim($usableId) === '') {
            throw InvalidMediaUsageException::emptyUsableId($usableType);
        }
    }

    /**
     * @throws InvalidMediaUsageException
     */
    public static function from(string $usableType, string $usableId, string $label): self
    {
        return new self($usableType, trim($usableId), trim($label));
    }

    public function usableType(): string
    {
        return $this->usableType;
    }

    public function usableId(): string
    {
        return $this->usableId;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** Two usages are the same place when they name the same thing, whatever their labels say. */
    public function equals(self $other): bool
    {
        return $this->usableType === $other->usableType
            && $this->usableId === $other->usableId;
    }
}
