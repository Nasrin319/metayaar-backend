<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\Permission;
use App\Enums\StaffRole;
use App\Models\Admin;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_creates_a_role_assigns_it_and_its_permissions_apply(): void
    {
        Sanctum::actingAs(Admin::factory()->create()->assignRole(StaffRole::Admin->value));

        $this->getJson(route('v1.admin.permissions.index'))
            ->assertOk()
            ->assertJsonPath('data', ['listings.review', 'users.manage', 'store.curate', 'staff.manage', 'statistics.view', 'settings.manage', 'finance.manage']);

        $roleId = $this->postJson(route('v1.admin.roles.store'), ['name' => 'Support', 'permissions' => ['users.manage']])
            ->assertCreated()
            ->assertJsonPath('data.permissions', ['users.manage'])
            ->assertJsonPath('data.locked', false)
            ->json('data.id');

        $support = Admin::factory()->create()->assignRole('Support');
        Sanctum::actingAs($support);
        $this->getJson(route('v1.admin.users.index'))->assertOk();
        $this->getJson(route('v1.admin.listings.index'))->assertForbidden();

        Sanctum::actingAs(Admin::factory()->create()->assignRole(StaffRole::Admin->value));
        $this->putJson(route('v1.admin.roles.update', $roleId), ['name' => 'Support', 'permissions' => ['listings.review']])
            ->assertOk()
            ->assertJsonPath('data.permissions', ['listings.review']);

        $this->getJson(route('v1.admin.roles.index'))
            ->assertOk()
            ->assertJsonPath('data.3.name', 'Support')
            ->assertJsonPath('data.3.admins_count', 1);

        Sanctum::actingAs($support->fresh());
        $this->getJson(route('v1.admin.users.index'))->assertForbidden();
        $this->getJson(route('v1.admin.listings.index'))->assertOk();
    }

    public function test_role_names_are_unique_and_permissions_must_exist(): void
    {
        Sanctum::actingAs(Admin::factory()->create()->assignRole(StaffRole::Admin->value));

        $this->postJson(route('v1.admin.roles.store'), ['name' => 'moderator', 'permissions' => ['everything']])
            ->assertJsonValidationErrors(['name', 'permissions.0']);
    }

    public function test_the_admin_role_cannot_be_changed_or_deleted_but_others_can_be_deleted(): void
    {
        Sanctum::actingAs(Admin::factory()->create()->assignRole(StaffRole::Admin->value));
        $admin = Role::findByName(StaffRole::Admin->value, Admin::GUARD);
        $curator = Role::findByName(StaffRole::Curator->value, Admin::GUARD);

        $this->putJson(route('v1.admin.roles.update', $admin), ['name' => 'admin', 'permissions' => []])->assertJsonValidationErrors('name');
        $this->deleteJson(route('v1.admin.roles.destroy', $admin))->assertJsonValidationErrors('name');
        $this->assertCount(count(Permission::cases()), $admin->fresh()->permissions);

        $this->deleteJson(route('v1.admin.roles.destroy', $curator))->assertNoContent();
        $this->assertNull(Role::where('name', 'curator')->first());
    }

    public function test_staff_without_the_permission_cannot_manage_roles(): void
    {
        Sanctum::actingAs(Admin::factory()->create()->assignRole(StaffRole::Moderator->value));

        $this->getJson(route('v1.admin.roles.index'))->assertForbidden();
        $this->postJson(route('v1.admin.roles.store'), ['name' => 'Boss', 'permissions' => ['staff.manage']])->assertForbidden();
    }
}
