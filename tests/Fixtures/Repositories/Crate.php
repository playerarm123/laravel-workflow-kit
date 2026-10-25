<?php

namespace Playerarm123\LaravelWorkflowKit\Tests\Fixtures\Repositories;

use App\Models\Concerns\KeyedByUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A root with an owned child table, for EloquentRepositoryTest.
 *
 * @property string $id
 * @property string $label
 */
class Crate extends Model
{
    use KeyedByUuid;

    protected $table = 'kit_crates';

    /**
     * @return HasMany<CrateItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CrateItem::class, 'crate_id');
    }
}
