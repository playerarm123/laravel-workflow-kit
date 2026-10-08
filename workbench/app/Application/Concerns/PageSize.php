<?php

namespace App\Application\Concerns;

/**
 * Row counts a list may be paged at, as the client names them.
 *
 * A page size outside this set is not a page size — it is the default. The settled
 * value reaches the UI through the paginator's own `per_page`, so nothing else has to
 * carry it.
 */
enum PageSize: int
{
    case Ten = 10;
    case TwentyFive = 25;
    case Fifty = 50;

    public static function fromInput(string $value): self
    {
        return self::tryFrom((int) $value) ?? self::Ten;
    }
}
