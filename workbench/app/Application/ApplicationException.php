<?php

namespace App\Application;

use RuntimeException;
use Throwable;

abstract class ApplicationException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message = '', int $code = 0, public array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
