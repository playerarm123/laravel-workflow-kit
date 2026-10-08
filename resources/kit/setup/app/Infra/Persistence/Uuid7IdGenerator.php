<?php

namespace App\Infra\Persistence;

use App\Domain\Shared\Ports\IdGenerator;
use Illuminate\Support\Str;
use Override;

class Uuid7IdGenerator implements IdGenerator
{
    #[Override]
    public function next(): string
    {
        return Str::uuid7()->toString();
    }
}
