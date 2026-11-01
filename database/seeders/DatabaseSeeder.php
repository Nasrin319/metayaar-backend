<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\StaffRole;
use App\Models\Admin;
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

        // Demo accounts, password "password": one website user per role (e.g. developer@example.com) and
        // one staff account per starting staff role (e.g. admin@example.com, for the admin panel).
        // Existing accounts are kept, so the seeder can run again on a seeded database.
        foreach (Role::cases() as $role) {
            $email = "{$role->value}@example.com";

            $user = User::firstWhere('email', $email) ?? User::factory()->create([
                'name' => ucfirst($role->value).' User',
                'email' => $email,
            ]);

            $user->assignRole($role->value);
        }

        foreach (StaffRole::cases() as $role) {
            $email = "{$role->value}@example.com";

            $admin = Admin::firstWhere('email', $email) ?? Admin::factory()->create([
                'name' => ucfirst($role->value),
                'email' => $email,
            ]);

            $admin->assignRole($role->value);
        }
    }
}
