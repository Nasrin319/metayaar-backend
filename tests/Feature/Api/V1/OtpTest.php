<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Enums\Role;
use App\Models\OtpCode;
use App\Models\User;
use App\Notifications\OtpCodeNotification;
use App\Support\Otp;
use App\Support\Sms\MsgwaySmsSender;
use App\Support\Sms\SmsSender;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OtpTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{string, string}> mobile and code of every SMS "sent" */
    private array $sentSms = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->app->instance(SmsSender::class, new class($this->sentSms) implements SmsSender
        {
            /** @param  list<array{string, string}>  $sent */
            public function __construct(private array &$sent) {}

            public function sendOtp(string $mobile, string $code): void
            {
                $this->sent[] = [$mobile, $code];
            }
        });
    }

    public function test_signup_code_is_emailed_and_stored_hashed(): void
    {
        Notification::fake();

        $this->postJson(route('v1.auth.otp.send'), ['channel' => 'email', 'destination' => 'Jane@Example.com', 'purpose' => 'signup'])
            ->assertOk()
            ->assertJsonPath('resend_in', Otp::RESEND_SECONDS);

        $code = null;
        Notification::assertSentOnDemand(OtpCodeNotification::class, function (OtpCodeNotification $notification, array $channels, object $notifiable) use (&$code): bool {
            $code = $notification->code;

            return $notifiable->routes['mail'] === 'jane@example.com' && $notification->purpose === OtpPurpose::Signup;
        });

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $stored = OtpCode::sole();
        $this->assertNotSame($code, $stored->code);
        $this->assertTrue(Hash::check($code, $stored->code));
    }

    public function test_mobile_code_is_sent_by_sms_to_the_normalized_number(): void
    {
        $this->postJson(route('v1.auth.otp.send'), ['channel' => 'mobile', 'destination' => '+98 912 345 ۶۷۸۹', 'purpose' => 'signup'])
            ->assertOk();

        $this->assertCount(1, $this->sentSms);
        $this->assertSame('09123456789', $this->sentSms[0][0]);
        $this->assertDatabaseHas('otp_codes', ['channel' => 'mobile', 'destination' => '09123456789']);
    }

    public function test_invalid_mobile_is_rejected(): void
    {
        $this->postJson(route('v1.auth.otp.send'), ['channel' => 'mobile', 'destination' => '12345', 'purpose' => 'signup'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['destination']);
    }

    public function test_codes_cannot_be_resent_right_away(): void
    {
        Notification::fake();
        $payload = ['channel' => 'email', 'destination' => 'jane@example.com', 'purpose' => 'signup'];

        $this->postJson(route('v1.auth.otp.send'), $payload)->assertOk();
        $this->postJson(route('v1.auth.otp.send'), $payload)->assertTooManyRequests();

        $this->travel(Otp::RESEND_SECONDS + 1)->seconds();
        $this->postJson(route('v1.auth.otp.send'), $payload)->assertOk();
        $this->assertDatabaseCount('otp_codes', 1);
    }

    public function test_signup_code_is_refused_for_a_registered_email(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'jane@example.com']);

        $this->postJson(route('v1.auth.otp.send'), ['channel' => 'email', 'destination' => 'jane@example.com', 'purpose' => 'signup'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['destination']);

        Notification::assertNothingSent();
    }

    public function test_login_codes_only_go_to_accounts_but_respond_the_same(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'jane@example.com']);

        $this->postJson(route('v1.auth.otp.send'), ['channel' => 'email', 'destination' => 'nobody@example.com', 'purpose' => 'login'])->assertOk();
        Notification::assertNothingSent();

        $this->postJson(route('v1.auth.otp.send'), ['channel' => 'email', 'destination' => 'jane@example.com', 'purpose' => 'login'])->assertOk();
        Notification::assertSentOnDemandTimes(OtpCodeNotification::class, 1);
    }

    public function test_registration_requires_valid_codes_and_verifies_email_and_mobile(): void
    {
        $payload = [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'mobile' => '9123456789',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ];

        $this->postJson(route('v1.auth.register', 'player'), $payload)
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['email_code', 'mobile_code']);

        $this->issueOtp(OtpChannel::Email, 'jane@example.com', OtpPurpose::Signup, '111111');
        $this->issueOtp(OtpChannel::Mobile, '09123456789', OtpPurpose::Signup, '222222');

        // a wrong mobile code doesn't use up the right email code
        $this->postJson(route('v1.auth.register', 'player'), [...$payload, 'email_code' => '111111', 'mobile_code' => '999999'])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['mobile_code']);

        $this->postJson(route('v1.auth.register', 'player'), [...$payload, 'email_code' => '111111', 'mobile_code' => '222222'])
            ->assertCreated()
            ->assertJsonPath('user.mobile', '09123456789');

        $user = User::firstWhere('email', 'jane@example.com');
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->mobile_verified_at);
        $this->assertDatabaseCount('otp_codes', 0);
    }

    public function test_codes_for_another_purpose_or_destination_are_rejected(): void
    {
        $this->issueOtp(OtpChannel::Email, 'jane@example.com', OtpPurpose::Login);
        $this->issueOtp(OtpChannel::Email, 'other@example.com', OtpPurpose::Signup);

        $this->postJson(route('v1.auth.register', 'player'), [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'email_code' => '123456',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertUnprocessable()->assertOnlyJsonValidationErrors(['email_code']);
    }

    public function test_expired_codes_and_too_many_wrong_guesses_are_rejected(): void
    {
        $user = User::factory()->create(['mobile' => '09123456789'])->assignRole(Role::Player->value);
        $login = fn (string $code) => $this->postJson(route('v1.auth.login.code', 'player'), [
            'channel' => 'mobile', 'destination' => $user->mobile, 'code' => $code,
        ]);

        $this->issueOtp(OtpChannel::Mobile, $user->mobile, OtpPurpose::Login);
        for ($i = 0; $i < Otp::MAX_ATTEMPTS; $i++) {
            $login('000000')->assertUnprocessable()->assertJsonValidationErrors(['code']);
        }
        $login('123456')->assertUnprocessable();

        OtpCode::query()->delete();
        $this->issueOtp(OtpChannel::Mobile, $user->mobile, OtpPurpose::Login);
        $this->travel(Otp::TTL_MINUTES + 1)->minutes();
        $login('123456')->assertUnprocessable();
    }

    public function test_user_can_log_in_with_a_mobile_code(): void
    {
        $user = User::factory()->create(['mobile' => '09123456789'])->assignRole(Role::Developer->value);
        $this->issueOtp(OtpChannel::Mobile, '09123456789', OtpPurpose::Login);

        $this->postJson(route('v1.auth.login.code', 'developer'), ['channel' => 'mobile', 'destination' => '0912 345 6789', 'code' => '123456'])
            ->assertOk()
            ->assertJsonPath('role', 'developer')
            ->assertJsonPath('user.id', $user->id);

        $this->assertTrue($user->tokens()->first()->can('role:developer'));
        $this->assertDatabaseCount('otp_codes', 0);
    }

    public function test_email_code_login_verifies_the_email(): void
    {
        $user = User::factory()->unverified()->create()->assignRole(Role::Player->value);
        $this->issueOtp(OtpChannel::Email, $user->email, OtpPurpose::Login);

        $this->postJson(route('v1.auth.login.code', 'player'), ['channel' => 'email', 'destination' => $user->email, 'code' => '123456'])
            ->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_code_login_requires_the_role_and_keeps_the_code(): void
    {
        $user = User::factory()->create()->assignRole(Role::Player->value);
        $this->issueOtp(OtpChannel::Email, $user->email, OtpPurpose::Login);

        $this->postJson(route('v1.auth.login.code', 'developer'), ['channel' => 'email', 'destination' => $user->email, 'code' => '123456'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['destination']);

        $this->postJson(route('v1.auth.login.code', 'player'), ['channel' => 'email', 'destination' => $user->email, 'code' => '123456'])
            ->assertOk();
    }

    public function test_password_can_be_reset_with_a_code_and_sessions_end(): void
    {
        $user = User::factory()->create(['mobile' => '09123456789']);
        $user->createToken('website');
        $this->issueOtp(OtpChannel::Mobile, '09123456789', OtpPurpose::ResetPassword);

        $this->postJson(route('v1.auth.password.reset.code'), [
            'channel' => 'mobile',
            'destination' => '09123456789',
            'code' => '123456',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_signed_in_user_verifies_their_email_with_a_code(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create()->assignRole(Role::Player->value);
        Sanctum::actingAs($user, ['role:player']);

        $this->postJson(route('v1.me.verification.send', 'email'))->assertOk();
        Notification::assertSentOnDemand(OtpCodeNotification::class, function (OtpCodeNotification $notification, array $channels, object $notifiable) use ($user): bool {
            return $notifiable->routes['mail'] === $user->email && $notification->purpose === OtpPurpose::Verify;
        });

        OtpCode::query()->delete();
        $this->issueOtp(OtpChannel::Email, $user->email, OtpPurpose::Verify);
        $this->postJson(route('v1.me.verification.verify', 'email'), ['code' => '123456'])
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_mobile_is_only_saved_after_its_code_is_confirmed(): void
    {
        $user = User::factory()->create()->assignRole(Role::Player->value);
        Sanctum::actingAs($user, ['role:player']);

        $this->postJson(route('v1.me.verification.send', 'mobile'), ['mobile' => '09123456789'])->assertOk();
        $this->assertSame([['09123456789', $this->sentSms[0][1]]], $this->sentSms);
        $this->assertNull($user->fresh()->mobile);

        $this->postJson(route('v1.me.verification.verify', 'mobile'), ['mobile' => '09123456789', 'code' => $this->sentSms[0][1]])
            ->assertOk()
            ->assertJsonPath('data.mobile', '09123456789');
        $this->assertNotNull($user->fresh()->mobile_verified_at);

        $this->deleteJson(route('v1.me.mobile.destroy'))->assertOk()->assertJsonPath('data.mobile', null);
    }

    public function test_mobile_of_another_account_cannot_be_added(): void
    {
        User::factory()->create(['mobile' => '09123456789']);
        Sanctum::actingAs(User::factory()->create()->assignRole(Role::Player->value), ['role:player']);

        $this->postJson(route('v1.me.verification.send', 'mobile'), ['mobile' => '09123456789'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile']);
        $this->assertSame([], $this->sentSms);
    }

    public function test_msgway_sends_the_code_with_the_template(): void
    {
        Http::fake(['api.msgway.com/*' => Http::response(['status' => 'success', 'referenceID' => '12345678'])]);

        (new MsgwaySmsSender('secret-key', 22891))->sendOtp('09123456789', '654321');

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://api.msgway.com/send'
            && $request->hasHeader('apiKey', 'secret-key')
            && $request['method'] === 'sms'
            && $request['mobile'] === '09123456789'
            && $request['templateID'] === 22891
            && $request['params'] === ['654321']);
    }

    public function test_msgway_errors_are_thrown(): void
    {
        Http::fake(['api.msgway.com/*' => Http::response(['status' => 'error', 'error' => ['code' => 2, 'message' => 'invalid template']], 400)]);

        $this->expectException(\RuntimeException::class);
        (new MsgwaySmsSender('secret-key', 1))->sendOtp('09123456789', '654321');
    }
}
