<?php

namespace App\Models;

use App\Models\Concerns\KeyedByUuid;
use App\Policies\MediaFolderPolicy;
use Database\Factories\MediaFolderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name'])]
#[UsePolicy(MediaFolderPolicy::class)]
class MediaFolder extends Model
{
    /** @use HasFactory<MediaFolderFactory> */
    use HasFactory, KeyedByUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }

    /**
     * @return HasMany<MediaItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MediaItem::class, 'folder_id');
    }
}
