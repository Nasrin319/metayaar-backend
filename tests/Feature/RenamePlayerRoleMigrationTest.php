<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RenamePlayerRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounts_and_signed_in_tokens_move_to_the_member_role(): void
    {
        $migration = require database_path('migrations/2026_11_04_120000_rename_player_role_to_member.php');
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('player', 'web'));
        $user->createToken('website', ['role:player']);
        $developerToken = $user->createToken('other', ['role:developer']);

        $migration->up();

        $this->assertSame(['member'], $user->fresh()->getRoleNames()->all());
        $this->assertFalse(Role::where('name', 'player')->exists());
        $this->assertSame(['role:member'], $user->tokens()->where('name', 'website')->first()->abilities);
        $this->assertSame(['role:developer'], $developerToken->accessToken->fresh()->abilities);

        $migration->down();

        $this->assertSame(['player'], $user->fresh()->getRoleNames()->all());
        $this->assertSame(['role:player'], $user->tokens()->where('name', 'website')->first()->abilities);
    }
}
