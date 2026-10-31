<?php

namespace Database\Factories;

use App\Enums\StoreTabRule;
use App\Models\StoreTab;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreTab>
 */
class StoreTabFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'label' => fake()->unique()->word(),
            'slug' => fake()->unique()->slug(2),
            'rule' => StoreTabRule::All,
            'group' => 1,
            'position' => 0,
            'is_active' => true,
        ];
    }

    /**
     * A tab that shows one category or tag.
     */
    public function matching(StoreTabRule $rule, ?string $value = null): static
    {
        return $this->state(fn () => ['rule' => $rule, 'value' => $value]);
    }
}
