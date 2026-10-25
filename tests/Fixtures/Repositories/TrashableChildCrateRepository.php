<?php

namespace Playerarm123\LaravelWorkflowKit\Tests\Fixtures\Repositories;

/**
 * A crate repository whose child model uses SoftDeletes, which syncChildren() refuses.
 */
final class TrashableChildCrateRepository extends CrateRepository
{
    protected string $childModel = TrashableCrateItem::class;
}
