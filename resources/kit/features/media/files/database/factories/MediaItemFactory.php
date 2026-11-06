<?php

namespace Database\Factories;

use App\Models\MediaFolder;
use App\Models\MediaItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaItem>
 */
class MediaItemFactory extends Factory
{
    /**
     * Define the model's default state. The paths name no real file; a test that reads the file
     * writes it on a fake disk first.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = fake()->uuid();

        return [
            'id' => fake()->uuid(),
            'folder_id' => null,
            'name' => 'Image '.fake()->unique()->numberBetween(1, 99999),
            'alt_text' => null,
            'disk' => 'public',
            'path' => "media/{$key}.webp",
            'thumbnail_path' => "media/{$key}_thumb.webp",
            'mime_type' => 'image/webp',
            'size_bytes' => 120_000,
            'thumbnail_size_bytes' => 12_000,
            'width' => 1600,
            'height' => 1200,
        ];
    }

    public function inFolder(MediaFolder $folder): static
    {
        return $this->state(fn (array $attributes) => ['folder_id' => $folder->id]);
    }
}
