<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:create-user {email : Email to log in with} {--name= : Display name (defaults to the part before @)} {--role=admin : One of player, developer, moderator, curator, admin}')]
#[Description('Create a verified user with a role, e.g. the first admin on a new server. Asks for the password.')]
class CreateUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $role = Role::tryFrom($this->option('role'));
        if (! $role) {
            $this->error('Unknown role. Use one of: '.implode(', ', Role::values()));

            return self::FAILURE;
        }

        $email = $this->argument('email');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Not a valid email address.');

            return self::FAILURE;
        }
        if (User::where('email', $email)->exists()) {
            $this->error("A user with that email already exists. Give it the role with: php artisan app:assign-role {$email} {$role->value}");

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

        // Roles may not exist yet on a fresh server; the seeder only creates what is missing.
        $this->callSilently('db:seed', ['--class' => RoleSeeder::class, '--force' => true]);

        $user = User::create([
            'name' => $this->option('name') ?: strstr($email, '@', true),
            'email' => $email,
            'password' => $password,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole($role->value);

        $this->info("Created {$user->email} with the {$role->value} role.");

        return self::SUCCESS;
    }
}
