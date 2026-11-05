<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\ListingStatus;
use App\Enums\StaffRole;
use App\Models\Admin;
use App\Models\Listing;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function superAdmin(): Admin
    {
        return Admin::factory()->create()->assignRole(StaffRole::SuperAdmin->value);
    }

    public function test_the_super_admin_role_holds_no_permissions_but_opens_every_menu(): void
    {
        $this->assertCount(0, Role::findByName(StaffRole::SuperAdmin->value, Admin::GUARD)->permissions);
        Sanctum::actingAs($this->superAdmin());

        $this->getJson(route('v1.admin.me'))
            ->assertJsonPath('data.is_super_admin', true)
            ->assertJsonPath('data.permissions', ['comments.moderate', 'content.manage', 'finance.manage', 'listings.review', 'settings.manage', 'staff.manage', 'statistics.view', 'store.curate', 'users.manage']);

        foreach (['v1.admin.listings.index', 'v1.admin.comments.index', 'v1.admin.users.index', 'v1.admin.settings.show', 'v1.admin.finance.show', 'v1.admin.staff.index', 'v1.admin.roles.index', 'v1.admin.statistics.downloads'] as $route) {
            $this->getJson(route($route))->assertOk();
        }
    }

    public function test_policy_rules_still_apply_to_a_super_admin(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $published = Listing::factory()->status(ListingStatus::Published)->create();

        $this->postJson(route('v1.admin.listings.approve', $published))->assertConflict();
    }

    public function test_super_admins_get_notifications_without_holding_permissions(): void
    {
        $super = $this->superAdmin();
        $listing = Listing::factory()->status(ListingStatus::Published)->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(route('v1.apps.comments.store', $listing), ['body' => 'نظر تازه'])->assertCreated();

        $this->assertCount(1, $super->notifications);
    }

    public function test_the_role_cannot_be_edited_or_deleted(): void
    {
        Sanctum::actingAs($this->superAdmin());
        $role = Role::findByName(StaffRole::SuperAdmin->value, Admin::GUARD);

        $this->putJson(route('v1.admin.roles.update', $role), ['name' => 'x', 'permissions' => []])
            ->assertJsonValidationErrors(['name' => 'نقش مدیر ارشد همیشه به همه بخش‌ها دسترسی دارد و قابل تغییر نیست.']);
        $this->deleteJson(route('v1.admin.roles.destroy', $role))->assertUnprocessable();

        $locked = collect($this->getJson(route('v1.admin.roles.index'))->json('data'))->firstWhere('name', 'super-admin');
        $this->assertTrue($locked['locked']);
    }

    public function test_only_super_admins_manage_super_admins(): void
    {
        $admin = Admin::factory()->create()->assignRole(StaffRole::Admin->value);
        $super = $this->superAdmin();
        $other = Admin::factory()->create()->assignRole(StaffRole::Moderator->value);
        $message = 'فقط مدیر ارشد می‌تواند نقش مدیر ارشد را بدهد، بردارد یا حساب مدیر ارشد را حذف کند.';
        Sanctum::actingAs($admin);

        // an admin can't make anyone a super admin, edit one, or delete one
        $this->putJson(route('v1.admin.staff.update', $admin), ['name' => $admin->name, 'email' => $admin->email, 'roles' => ['admin', 'super-admin']])
            ->assertForbidden()->assertJsonPath('message', $message);
        $this->putJson(route('v1.admin.staff.update', $super), ['name' => 'Taken over', 'email' => $super->email, 'password' => 'new-password-123', 'roles' => ['super-admin']])
            ->assertForbidden();
        $this->deleteJson(route('v1.admin.staff.destroy', $super))->assertForbidden();
        $this->assertSame($super->name, $super->fresh()->name);

        // a super admin can
        Sanctum::actingAs($super);
        $this->putJson(route('v1.admin.staff.update', $other), ['name' => $other->name, 'email' => $other->email, 'roles' => ['super-admin']])->assertOk();
        $this->assertTrue($other->fresh()->isSuperAdmin());
    }

    public function test_a_super_admin_cannot_drop_their_own_role(): void
    {
        Sanctum::actingAs($super = $this->superAdmin());

        $this->putJson(route('v1.admin.staff.update', $super), ['name' => $super->name, 'email' => $super->email, 'roles' => ['admin']])
            ->assertJsonValidationErrors(['roles' => 'نمی‌توانید نقش مدیر ارشد را از حساب خودتان بردارید.']);
    }

    public function test_reseeding_gives_the_admin_role_every_permission_again(): void
    {
        $role = Role::findByName(StaffRole::Admin->value, Admin::GUARD);
        $role->syncPermissions(['users.manage']);

        $this->seed(RoleSeeder::class);

        $this->assertCount(9, $role->fresh()->permissions);
    }

    public function test_the_command_can_make_an_account_a_super_admin(): void
    {
        $admin = Admin::factory()->create(['email' => 'owner@example.com']);

        $this->artisan('app:staff-access', ['email' => 'owner@example.com', '--fix' => true, '--super' => true])
            ->expectsOutputToContain('super admin: every menu')
            ->assertSuccessful();

        $this->assertTrue($admin->fresh()->isSuperAdmin());
    }
}
