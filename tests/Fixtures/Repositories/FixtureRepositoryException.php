<?php

namespace Playerarm123\LaravelWorkflowKit\Tests\Fixtures\Repositories;

use App\Domain\Shared\Exceptions\RepositoryException;

final class FixtureRepositoryException extends RepositoryException
{
    public static function entityName(): string
    {
        return 'Fixture';
    }
}
