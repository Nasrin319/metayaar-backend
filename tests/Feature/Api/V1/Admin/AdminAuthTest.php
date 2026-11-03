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

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_staff_sign_in_and_see_their_permissions(): void
    {
        $admin = Admin::factory()->create(['email' => 'mod@metayaar.test'])->assignRole(StaffRole::Moderator->value);

        $token = $this->postJson(route('v1.admin.auth.login'), ['email' => 'mod@metayaar.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('admin.roles', ['moderator'])
            ->assertJsonPath('admin.permissions', ['comments.moderate', 'listings.review'])
            ->json('token');
        $this->assertNotNull($admin->fresh()->last_login_at);

        $this->withToken($token)->getJson(route('v1.admin.me'))->assertOk()->assertJsonPath('data.email', 'mod@metayaar.test');

        $this->withToken($token)->postJson(route('v1.admin.auth.logout'))->assertOk();
        $this->assertCount(0, $admin->tokens()->get());
    }

    public function test_wrong_password_and_website_accounts_are_refused(): void
    {
        Admin::factory()->create(['email' => 'mod@metayaar.test']);
        User::factory()->create(['email' => 'player@metayaar.test'])->assignRole(Role::Player->value);

        $this->postJson(route('v1.admin.auth.login'), ['email' => 'mod@metayaar.test', 'password' => 'wrong'])
            ->assertJsonValidationErrors('email');
        $this->postJson(route('v1.admin.auth.login'), ['email' => 'player@metayaar.test', 'password' => 'password'])
            ->assertJsonValidationErrors('email');
    }

    public function test_staff_tokens_cannot_use_the_website_api_and_user_tokens_cannot_use_the_admin_api(): void
    {
        Sanctum::actingAs(Admin::factory()->create()->assignRole(StaffRole::Admin->value));
        $this->getJson(route('v1.me'))->assertForbidden();
        $this->getJson(route('v1.developer.apps.index'))->assertForbidden();

        Sanctum::actingAs(User::factory()->create()->assignRole(Role::Developer->value));
        $this->getJson(route('v1.admin.me'))->assertForbidden();
    }
}
