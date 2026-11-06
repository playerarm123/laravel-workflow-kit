<?php

namespace App\Application\Media\UseCases\CreateMediaFolder;

use Spatie\LaravelData\Data;

final class CreateMediaFolderCommand extends Data
{
    public function __construct(
        public readonly string $name,
    ) {}
}
