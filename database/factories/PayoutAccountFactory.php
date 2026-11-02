<?php

namespace Database\Factories;

use App\Models\PayoutAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayoutAccount>
 */
class PayoutAccountFactory extends Factory
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
            'holder_name' => fake()->name(),
            'national_id' => '0499370899',
            'bank_name' => 'بانک ملی',
            'sheba' => 'IR062960000000100324200001',
            'card_number' => null,
            'postal_code' => fake()->numerify('1#########'),
            'address' => fake()->address(),
        ];
    }
}
