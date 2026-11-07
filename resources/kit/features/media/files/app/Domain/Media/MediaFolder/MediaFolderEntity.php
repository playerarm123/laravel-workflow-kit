<?php

namespace App\Domain\Media\MediaFolder;

use App\Domain\Media\MediaFolder\Exceptions\InvalidMediaFolderNameException;
use App\Domain\Shared\AggregateRoot;
use Override;

/**
 * A folder of the image library. Folders do not nest, and an image sits in one folder or none. A
 * name is unique whatever its case, which the use cases check before they write.
 */
final class MediaFolderEntity extends AggregateRoot
{
    private function __construct(
        private string $id,
        private string $name,
    ) {}

    #[Override]
    public static function entityName(): string
    {
        return 'Media Folder';
    }

    /**
     * ================================================
     * Building Section
     * ================================================
     */

    /**
     * @throws InvalidMediaFolderNameException
     */
    public static function create(
        string $id,
        string $name,
    ): self {
        return new self(
            id: $id,
            name: self::guardName($name),
        );
    }

    public static function reconstitute(
        string $id,
        string $name,
    ): self {
        return new self(
            id: $id,
            name: $name,
        );
    }

    /**
     * ================================================
     * Behavior Section
     * ================================================
     */

    /**
     * @throws InvalidMediaFolderNameException
     */
    public function rename(string $name): void
    {
        $this->name = self::guardName($name);
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

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @throws InvalidMediaFolderNameException
     */
    private static function guardName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw InvalidMediaFolderNameException::empty();
        }

        if (mb_strlen($name) > InvalidMediaFolderNameException::MAX_LENGTH) {
            throw InvalidMediaFolderNameException::tooLong($name);
        }

        return $name;
    }
}
