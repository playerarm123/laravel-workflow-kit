<?php

namespace Database\Factories;

use App\Models\MediaFolder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaFolder>
 */
class MediaFolderFactory extends Factory
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
            'name' => 'Folder '.fake()->unique()->numberBetween(1, 99999),
        ];
    }
}
