<?php

namespace App\Models;

use App\Models\Concerns\KeyedByUuid;
use App\Policies\AuditEntryPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\AuditEntryFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of the audit log. Only `App\Infra\Audit\DatabaseAuditLog` writes it.
 *
 * @property string $id
 * @property string $event
 * @property string $subject_type
 * @property string $subject_id
 * @property string|null $actor_id null when the system acted
 * @property array<string, scalar|null> $data
 * @property CarbonImmutable $occurred_at
 */
#[UsePolicy(AuditEntryPolicy::class)]
class AuditEntry extends Model
{
    /** @use HasFactory<AuditEntryFactory> */
    use HasFactory, KeyedByUuid;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
