<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

#[Signature('app:create-admin {email : Email to sign in to the admin panel with} {--name= : Display name (defaults to the part before @)} {--role=admin : A staff role, e.g. admin, moderator, curator}')]
#[Description('Create a staff account for the admin panel, e.g. the first admin on a new server. Asks for the password.')]
class CreateAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Not a valid email address.');

            return self::FAILURE;
        }
        if (Admin::where('email', $email)->exists()) {
            $this->error('A staff account with that email already exists.');

            return self::FAILURE;
        }

        // Roles may not exist yet on a fresh server; the seeder only creates what is missing.
        $this->callSilently('db:seed', ['--class' => RoleSeeder::class, '--force' => true]);

        $role = $this->option('role');
        if (! Role::where(['name' => $role, 'guard_name' => Admin::GUARD])->exists()) {
            $roles = Role::where('guard_name', Admin::GUARD)->pluck('name')->implode(', ');
            $this->error("Unknown staff role. Use one of: {$roles}");

            return self::FAILURE;
        }

        $password = $this->secret('Password (at least 8 characters)');
        if (strlen((string) $password) < 8) {
            $this->error('The password must be at least 8 characters.');

            return self::FAILURE;
        }
        if ($this->secret('Repeat the password') !== $password) {
            $this->error('The passwords do not match.');

            return self::FAILURE;
        }

        $admin = Admin::create([
            'name' => $this->option('name') ?: strstr($email, '@', true),
            'email' => $email,
            'password' => $password,
        ]);
        $admin->assignRole($role);

        $this->info("Created staff account {$admin->email} with the {$role} role.");

        return self::SUCCESS;
    }
}
