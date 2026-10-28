<?php

namespace Database\Factories;

use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->unique()->words(3, true),
            'type' => ListingType::Apk,
            'tagline' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'category' => 'action',
            'price' => 0,
            'package_name' => 'com.'.fake()->unique()->domainWord().'.vr',
            'external_url' => null,
        ];
    }

    /**
     * A listing that links to an external store page or experience.
     */
    public function external(ListingType $type = ListingType::WebXr): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
            'package_name' => null,
            'external_url' => fake()->url(),
        ]);
    }

    public function status(ListingStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }
}
