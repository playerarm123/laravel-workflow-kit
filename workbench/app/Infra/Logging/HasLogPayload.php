<?php

namespace App\Infra\Logging;

interface HasLogPayload
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
