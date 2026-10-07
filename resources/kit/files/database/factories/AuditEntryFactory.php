<?php

namespace Database\Factories;

use App\Models\AuditEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Entries for tests that read the log. The application writes it only through
 * `App\Infra\Audit\DatabaseAuditLog`, which a seeder or a factory never replaces.
 *
 * @extends Factory<AuditEntry>
 */
class AuditEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => fake()->uuid(),
            'event' => 'invoice.issued',
            'subject_type' => 'invoice',
            'subject_id' => fake()->uuid(),
            'actor_id' => null,
            'data' => [],
            'occurred_at' => now(),
        ];
    }

    public function about(string $subjectType, string $subjectId, string $event): static
    {
        return $this->state(fn (array $attributes) => [
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'event' => $event,
        ]);
    }

    public function by(string $actorId): static
    {
        return $this->state(fn (array $attributes) => ['actor_id' => $actorId]);
    }
}
