<?php

namespace App\Application\Concerns;

use RuntimeException;
use Throwable;

/**
 * A list page's read the database refused — a broken query or a lost connection, never "no
 * rows". The adapter that failed is named, so the log says which list broke without the
 * framework's exception leaving the infrastructure layer.
 */
final class ListQueryException extends RuntimeException
{
    public static function failed(string $query, Throwable $previous): self
    {
        return new self(sprintf('List query [%s] failed.', $query), 0, $previous);
    }
}
