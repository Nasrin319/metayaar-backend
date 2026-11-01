<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\StaffRole;
use App\Models\Admin;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = Admin::factory()->create()->assignRole(StaffRole::Admin->value);
    }

    public function test_admin_creates_edits_and_deletes_staff_accounts(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson(route('v1.admin.staff.store'), [
            'name' => 'Sara',
            'email' => 'sara@metayaar.test',
            'password' => 'secret-password',
            'roles' => ['moderator', 'curator'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.roles', ['moderator', 'curator'])
            ->assertJsonPath('data.permissions', ['listings.review', 'store.curate'])
            ->json('data.id');

        $sara = Admin::find($id);
        $sara->createToken('panel');

        $this->putJson(route('v1.admin.staff.update', $id), [
            'name' => 'Sara K',
            'email' => 'sara@metayaar.test',
            'password' => 'another-password',
            'roles' => ['curator'],
        ])->assertOk()->assertJsonPath('data.roles', ['curator']);

        $this->assertTrue(Hash::check('another-password', $sara->fresh()->password));
        $this->assertCount(0, $sara->tokens()->get());

        // without a password the old one stays
        $this->putJson(route('v1.admin.staff.update', $id), ['name' => 'Sara K', 'email' => 'sara@metayaar.test', 'roles' => []])->assertOk();
        $this->assertTrue(Hash::check('another-password', $sara->fresh()->password));

        $this->getJson(route('v1.admin.staff.index'))->assertOk()->assertJsonCount(2, 'data');

        $this->deleteJson(route('v1.admin.staff.destroy', $id))->assertNoContent();
        $this->assertDatabaseMissing('admins', ['id' => $id]);
    }

    public function test_new_accounts_need_a_password_a_unique_email_and_existing_roles(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson(route('v1.admin.staff.store'), [
            'name' => 'Copy',
            'email' => $this->admin->email,
            'roles' => ['owner'],
        ])->assertJsonValidationErrors(['email', 'password', 'roles.0']);
    }

    public function test_admins_cannot_remove_their_own_admin_role_or_delete_themselves(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson(route('v1.admin.staff.update', $this->admin), [
            'name' => $this->admin->name,
            'email' => $this->admin->email,
            'roles' => ['moderator'],
        ])->assertJsonValidationErrors('roles');
        $this->deleteJson(route('v1.admin.staff.destroy', $this->admin))->assertJsonValidationErrors('admin');

        $this->assertTrue($this->admin->fresh()->hasRole(StaffRole::Admin->value));
    }

    public function test_staff_without_the_permission_cannot_manage_staff(): void
    {
        Sanctum::actingAs(Admin::factory()->create()->assignRole(StaffRole::Moderator->value));

        $this->getJson(route('v1.admin.staff.index'))->assertForbidden();
        $this->deleteJson(route('v1.admin.staff.destroy', $this->admin))->assertForbidden();
    }
}
