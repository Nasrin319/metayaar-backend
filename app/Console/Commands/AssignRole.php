<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:assign-role {email : Email of an existing user} {role : One of player, developer, moderator, curator, admin}')]
#[Description('Give a user a role, e.g. to create the first admin')]
class AssignRole extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $role = Role::tryFrom($this->argument('role'));
        if (! $role) {
            $this->error('Unknown role. Use one of: '.implode(', ', Role::values()));

            return self::FAILURE;
        }

        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        $user->assignRole($role->value);
        $this->info("{$user->email} now has the {$role->value} role.");

        return self::SUCCESS;
    }
}
