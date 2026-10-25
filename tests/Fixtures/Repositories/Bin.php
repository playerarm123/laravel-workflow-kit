<?php

namespace Playerarm123\LaravelWorkflowKit\Tests\Fixtures\Repositories;

use App\Models\Concerns\KeyedByUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A soft-deletable root, for EloquentRepositoryTest.
 *
 * @property string $id
 * @property string $label
 */
class Bin extends Model
{
    use KeyedByUuid, SoftDeletes;

    protected $table = 'kit_bins';
}
