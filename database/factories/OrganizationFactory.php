<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['account_type' => AccountType::Organization]),
            'name' => fake()->company(),
            'national_id' => fake()->numerify('###########'),
            'phone' => fake()->numerify('021########'),
        ];
    }
}
