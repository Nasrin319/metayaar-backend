<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = User::factory()->create()->assignRole(Role::Admin->value);
    }

    public function test_admin_searches_users_and_changes_their_roles(): void
    {
        Sanctum::actingAs($this->admin);
        $user = User::factory()->create(['email' => 'sara@studio.test'])->assignRole(Role::Player->value);

        $this->getJson(route('v1.admin.users.index', ['search' => 'SARA@']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'sara@studio.test');

        $this->putJson(route('v1.admin.users.roles', $user), ['roles' => ['player', 'moderator']])
            ->assertOk()
            ->assertJsonPath('data.roles', ['player', 'moderator'])
            ->assertJsonPath('data.permissions', ['listings.review']);

        $this->putJson(route('v1.admin.users.roles', $user), ['roles' => ['owner']])->assertJsonValidationErrors('roles.0');
    }

    public function test_admin_cannot_remove_their_own_admin_role(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson(route('v1.admin.users.roles', $this->admin), ['roles' => ['player']])
            ->assertJsonValidationErrors('roles');
        $this->assertTrue($this->admin->fresh()->hasRole(Role::Admin->value));
    }

    public function test_moderators_cannot_manage_users(): void
    {
        $moderator = User::factory()->create()->assignRole(Role::Moderator->value);
        Sanctum::actingAs($moderator);

        $this->getJson(route('v1.admin.users.index'))->assertForbidden();
        $this->putJson(route('v1.admin.users.roles', $moderator), ['roles' => ['admin']])->assertForbidden();
        $this->assertFalse($moderator->fresh()->hasRole(Role::Admin->value));
    }

    public function test_assign_role_command_creates_the_first_admin(): void
    {
        $user = User::factory()->create(['email' => 'owner@metayaar.test']);

        $this->artisan('app:assign-role', ['email' => 'owner@metayaar.test', 'role' => 'admin'])->assertSuccessful();
        $this->artisan('app:assign-role', ['email' => 'owner@metayaar.test', 'role' => 'owner'])->assertFailed();

        $this->assertTrue($user->fresh()->hasRole(Role::Admin->value));
    }
}
