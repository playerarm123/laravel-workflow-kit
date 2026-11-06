<?php

namespace App\Domain\Media\MediaItem;

use App\Domain\Media\MediaItem\Exceptions\InvalidMediaItemNameException;
use App\Domain\Media\MediaItem\Exceptions\MediaItemInUseException;
use App\Domain\Media\MediaItem\ValueObjects\MediaFile;
use App\Domain\Media\MediaItem\ValueObjects\MediaUsage;
use App\Domain\Shared\AggregateRoot;
use Override;

/**
 * One image in the library: its folder, the name and alt text users may change, and the
 * stored file, which never changes. A new picture is a new item, so whatever uses the old one
 * never sees it change silently. Where the image is used is written by the aggregates that use
 * it, so the entity does not hold it; it is handed the usages when it must decide on a delete.
 */
final class MediaItemEntity extends AggregateRoot
{
    private function __construct(
        private string $id,
        private ?string $folderId,
        private string $name,
        private ?string $altText,
        private MediaFile $file,
    ) {}

    #[Override]
    public static function entityName(): string
    {
        return 'Media Item';
    }

    /**
     * ================================================
     * Building Section
     * ================================================
     */

    /**
     * @throws InvalidMediaItemNameException
     */
    public static function create(
        string $id,
        ?string $folderId,
        string $name,
        MediaFile $file,
    ): self {
        return new self(
            id: $id,
            folderId: $folderId,
            name: self::guardName($name),
            altText: null,
            file: $file,
        );
    }

    public static function reconstitute(
        string $id,
        ?string $folderId,
        string $name,
        ?string $altText,
        MediaFile $file,
    ): self {
        return new self(
            id: $id,
            folderId: $folderId,
            name: $name,
            altText: $altText,
            file: $file,
        );
    }

    /**
     * ================================================
     * Behavior Section
     * ================================================
     */

    /**
     * A blank alt text is kept as none.
     *
     * @throws InvalidMediaItemNameException
     */
    public function changeInfos(string $name, ?string $altText): void
    {
        $altText = $altText === null ? null : trim($altText);

        $this->name = self::guardName($name);
        $this->altText = $altText === '' ? null : $altText;
    }

    /**
     * $folderId is null to take the image out of every folder.
     */
    public function moveToFolder(?string $folderId): void
    {
        $this->folderId = $folderId;
    }

    /**
     * ================================================
     * Getter Section
     * ================================================
     */
    public function id(): string
    {
        return $this->id;
    }

    public function folderId(): ?string
    {
        return $this->folderId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function altText(): ?string
    {
        return $this->altText;
    }

    public function file(): MediaFile
    {
        return $this->file;
    }

    /**
     * ================================================
     * Asserting Section
     * ================================================
     */

    /**
     * @param  list<MediaUsage>  $usages  where the image is used now
     *
     * @throws MediaItemInUseException
     */
    public function assertNotInUse(array $usages): void
    {
        if ($usages !== []) {
            throw MediaItemInUseException::inUse($this, $usages);
        }
    }

    /**
     * @throws InvalidMediaItemNameException
     */
    private static function guardName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw InvalidMediaItemNameException::empty();
        }

        if (mb_strlen($name) > InvalidMediaItemNameException::MAX_LENGTH) {
            throw InvalidMediaItemNameException::tooLong($name);
        }

        return $name;
    }
}
