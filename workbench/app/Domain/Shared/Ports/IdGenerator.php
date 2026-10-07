<?php

namespace App\Domain\Shared\Ports;

/**
 * The domain's way out when it builds entities in a batch without knowing how many in advance.
 * The Domain layer cannot touch Illuminate, so it asks for ids through this interface instead of calling Str::uuid7().
 */
interface IdGenerator
{
    public function next(): string;
}
