<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAccountCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_first_admin_on_a_fresh_database(): void
    {
        $this->artisan('app:create-admin', ['email' => 'owner@metayaar.test', '--name' => 'Owner'])
            ->expectsQuestion('Password (at least 8 characters)', 'secret-pass')
            ->expectsQuestion('Repeat the password', 'secret-pass')
            ->expectsOutput('Created staff account owner@metayaar.test with the admin role.')
            ->assertSuccessful();

        $admin = Admin::firstWhere('email', 'owner@metayaar.test');
        $this->assertSame('Owner', $admin->name);
        $this->assertTrue(Hash::check('secret-pass', $admin->password));
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue($admin->hasPermissionTo('staff.manage'));
        $this->assertDatabaseMissing('users', ['email' => 'owner@metayaar.test']);
    }

    public function test_create_admin_refuses_an_existing_email_an_unknown_role_and_mismatched_passwords(): void
    {
        Admin::factory()->create(['email' => 'taken@metayaar.test']);

        $this->artisan('app:create-admin', ['email' => 'taken@metayaar.test'])->assertFailed();
        $this->artisan('app:create-admin', ['email' => 'new@metayaar.test', '--role' => 'owner'])->assertFailed();

        $this->artisan('app:create-admin', ['email' => 'new@metayaar.test'])
            ->expectsQuestion('Password (at least 8 characters)', 'secret-pass')
            ->expectsQuestion('Repeat the password', 'other-pass')
            ->expectsOutput('The passwords do not match.')
            ->assertFailed();

        $this->assertDatabaseMissing('admins', ['email' => 'new@metayaar.test']);
    }

    public function test_it_creates_a_verified_website_user(): void
    {
        $this->artisan('app:create-user', ['email' => 'studio@metayaar.test', '--role' => 'developer'])
            ->expectsQuestion('Password (at least 8 characters)', 'secret-pass')
            ->expectsQuestion('Repeat the password', 'secret-pass')
            ->expectsOutput('Created studio@metayaar.test with the developer role.')
            ->assertSuccessful();

        $user = User::firstWhere('email', 'studio@metayaar.test');
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->hasRole('developer'));

        $this->artisan('app:create-user', ['email' => 'other@metayaar.test', '--role' => 'admin'])->assertFailed();
    }
}
