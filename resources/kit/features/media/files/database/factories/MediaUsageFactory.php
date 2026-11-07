<?php

namespace Database\Factories;

use App\Models\MediaItem;
use App\Models\MediaUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaUsage>
 */
class MediaUsageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => fake()->uuid(),
            'media_item_id' => MediaItem::factory(),
            'usable_type' => 'profile',
            'usable_id' => fake()->uuid(),
            'label' => fake()->name(),
        ];
    }
}
