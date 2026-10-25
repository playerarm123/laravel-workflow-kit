<?php

namespace Playerarm123\LaravelWorkflowKit\Tests\Fixtures\Repositories;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The same child table read through SoftDeletes, which syncChildren() must refuse.
 */
class TrashableCrateItem extends CrateItem
{
    use SoftDeletes;
}
