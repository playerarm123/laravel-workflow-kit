<?php

namespace App\Models;

use App\Models\Concerns\KeyedByUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 */
#[Fillable(['id', 'name'])]
class Product extends Model
{
    use KeyedByUuid;
}
