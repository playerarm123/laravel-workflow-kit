<?php

namespace App\Infra\Persistence;

use App\Domain\Shared\Ports\IdGenerator;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the ports the infrastructure implements to their adapters. `kit:apply` writes a new
 * binding above the `kit:bindings` marker (structure.md).
 */
class PersistenceServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        IdGenerator::class => Uuid7IdGenerator::class,
        // kit:bindings
    ];
}
