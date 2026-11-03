<?php

namespace Tests\Feature;

use App\Models\Admin;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StaffAccessCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_explains_why_an_account_without_a_role_sees_no_menus(): void
    {
        $this->seed(RoleSeeder::class);
        Admin::factory()->create(['email' => 'boss@example.com']);

        $this->artisan('app:staff-access', ['email' => 'boss@example.com'])
            ->expectsOutputToContain('Roles: (none)')
            ->expectsOutputToContain('The account has no staff role')
            ->assertSuccessful();
    }

    public function test_fix_creates_missing_permissions_and_makes_the_account_an_admin(): void
    {
        $this->seed(RoleSeeder::class);
        Permission::findByName('settings.manage', Admin::GUARD)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::factory()->create(['email' => 'boss@example.com']);

        $this->artisan('app:staff-access', ['email' => ' Boss@Example.com ', '--fix' => true])->assertSuccessful();

        $admin = $admin->fresh();
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($admin->hasPermissionTo('settings.manage'));
        $this->assertTrue($admin->hasPermissionTo('finance.manage'));
    }

    public function test_an_unknown_email_says_where_to_look(): void
    {
        $this->artisan('app:staff-access', ['email' => 'nobody@example.com'])
            ->expectsOutputToContain('No staff account with that email')
            ->assertFailed();
    }
}
