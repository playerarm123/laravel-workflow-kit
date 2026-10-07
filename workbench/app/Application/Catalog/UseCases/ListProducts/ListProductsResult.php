<?php

namespace App\Application\Catalog\UseCases\ListProducts;

use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\EnumCast;
use Spatie\LaravelData\Data;

final class ListProductsResult extends Data
{
    public function __construct(
        // define the result payload here, e.g.
        // public readonly string $id,
        // #[WithCast(EnumCast::class)]
        // public readonly CountryCode $countryCode,
    ) {}
}
