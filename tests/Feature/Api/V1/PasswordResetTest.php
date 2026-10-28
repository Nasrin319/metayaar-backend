<?php

namespace Tests\Feature\Api\V1;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_reset_link_is_emailed_and_points_to_the_website(): void
    {
        Notification::fake();
        $user = User::factory()->create()->assignRole(Role::Player->value);

        $this->postJson(route('v1.auth.password.email'), ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $mail = $notification->toMail($user);

            return str_starts_with($mail->actionUrl, config('app.frontend_url').'/reset-password?token=')
                && str_contains($mail->actionUrl, urlencode($user->email));
        });
    }

    public function test_unknown_email_gets_the_same_answer(): void
    {
        Notification::fake();

        $this->postJson(route('v1.auth.password.email'), ['email' => 'nobody@example.com'])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_password_can_be_reset_with_the_emailed_token_and_sessions_end(): void
    {
        Notification::fake();
        $user = User::factory()->create()->assignRole(Role::Player->value);
        $user->createToken('desktop');

        $this->postJson(route('v1.auth.password.email'), ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->postJson(route('v1.auth.password.reset'), [
            'token' => 'wrong',
            'email' => $user->email,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertJsonValidationErrors('token');

        $this->postJson(route('v1.auth.password.reset'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
        $this->assertCount(0, $user->tokens()->get());
    }
}
