<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_verified_admin_on_a_fresh_database(): void
    {
        $this->artisan('app:create-user', ['email' => 'owner@metayaar.test', '--name' => 'Owner'])
            ->expectsQuestion('Password (at least 8 characters)', 'secret-pass')
            ->expectsQuestion('Repeat the password', 'secret-pass')
            ->expectsOutput('Created owner@metayaar.test with the admin role.')
            ->assertSuccessful();

        $user = User::firstWhere('email', 'owner@metayaar.test');
        $this->assertSame('Owner', $user->name);
        $this->assertTrue(Hash::check('secret-pass', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->hasRole('admin'));
    }

    public function test_it_refuses_an_existing_email_and_mismatched_passwords(): void
    {
        User::factory()->create(['email' => 'taken@metayaar.test']);

        $this->artisan('app:create-user', ['email' => 'taken@metayaar.test'])->assertFailed();

        $this->artisan('app:create-user', ['email' => 'new@metayaar.test'])
            ->expectsQuestion('Password (at least 8 characters)', 'secret-pass')
            ->expectsQuestion('Repeat the password', 'other-pass')
            ->expectsOutput('The passwords do not match.')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'new@metayaar.test']);
    }
}
