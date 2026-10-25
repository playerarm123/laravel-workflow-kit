<?php

namespace Playerarm123\LaravelWorkflowKit\Tests\Fixtures\Repositories;

use App\Models\Concerns\KeyedByUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $crate_id
 * @property string $sku
 */
class CrateItem extends Model
{
    use KeyedByUuid;

    protected $table = 'kit_crate_items';
}
