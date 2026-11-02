<?php

namespace Database\Factories;

use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WalletTransaction>
 */
class WalletTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->numberBetween(1, 100) * 1000;

        return [
            'user_id' => User::factory(),
            'type' => WalletTransactionType::Sale,
            'amount' => $amount,
            'balance_after' => $amount,
        ];
    }
}
