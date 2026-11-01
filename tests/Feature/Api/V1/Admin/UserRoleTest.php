<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\Role;
use App\Enums\StaffRole;
use App\Models\Admin;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_searches_users_and_changes_their_website_roles(): void
    {
        Sanctum::actingAs(Admin::factory()->create()->assignRole(StaffRole::Admin->value));
        $user = User::factory()->create(['email' => 'sara@studio.test'])->assignRole(Role::Player->value);

        $this->getJson(route('v1.admin.users.index', ['search' => 'SARA@']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'sara@studio.test');

        $this->putJson(route('v1.admin.users.roles', $user), ['roles' => ['player', 'developer']])
            ->assertOk()
            ->assertJsonPath('data.roles', ['player', 'developer']);

        // staff roles are not website roles
        $this->putJson(route('v1.admin.users.roles', $user), ['roles' => ['moderator']])->assertJsonValidationErrors('roles.0');
    }

    public function test_staff_without_the_permission_cannot_manage_users(): void
    {
        Sanctum::actingAs(Admin::factory()->create()->assignRole(StaffRole::Moderator->value));
        $user = User::factory()->create()->assignRole(Role::Player->value);

        $this->getJson(route('v1.admin.users.index'))->assertForbidden();
        $this->putJson(route('v1.admin.users.roles', $user), ['roles' => ['developer']])->assertForbidden();
        $this->assertFalse($user->fresh()->hasRole(Role::Developer->value));
    }

    public function test_assign_role_command_gives_a_website_role(): void
    {
        $user = User::factory()->create(['email' => 'studio@metayaar.test']);

        $this->artisan('app:assign-role', ['email' => 'studio@metayaar.test', 'role' => 'developer'])->assertSuccessful();
        $this->artisan('app:assign-role', ['email' => 'studio@metayaar.test', 'role' => 'admin'])->assertFailed();

        $this->assertSame(['developer'], $user->fresh()->getRoleNames()->all());
    }
}
