<?php

namespace App\Application\Catalog\UseCases\ListProducts;

use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\EnumCast;
use Spatie\LaravelData\Data;

final class ListProductsCommand extends Data
{
    public function __construct(
        // define the command payload here, e.g.
        // public readonly string $name,
        // #[WithCast(EnumCast::class)]
        // public readonly CountryCode $countryCode,
    ) {}
}
