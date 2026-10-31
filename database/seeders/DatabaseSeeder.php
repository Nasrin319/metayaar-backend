<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([RoleSeeder::class, StoreTabSeeder::class]);

        // One demo account per role, password "password" (e.g. developer@example.com).
        // Existing accounts are kept, so the seeder can run again on a seeded database.
        foreach (Role::cases() as $role) {
            $email = "{$role->value}@example.com";

            $user = User::firstWhere('email', $email) ?? User::factory()->create([
                'name' => ucfirst($role->value).' User',
                'email' => $email,
            ]);

            $user->assignRole($role->value);
        }
    }
}
