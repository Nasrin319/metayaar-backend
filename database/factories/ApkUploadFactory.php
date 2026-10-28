<?php

namespace Database\Factories;

use App\Models\ApkUpload;
use App\Models\Listing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApkUpload>
 */
class ApkUploadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $listing = Listing::factory();

        return [
            'listing_id' => $listing,
            'user_id' => fn (array $attributes) => Listing::find($attributes['listing_id'])->user_id,
            'original_name' => fake()->slug(2).'.apk',
            'size' => fake()->numberBetween(1024, ApkUpload::CHUNK_SIZE),
        ];
    }
}
