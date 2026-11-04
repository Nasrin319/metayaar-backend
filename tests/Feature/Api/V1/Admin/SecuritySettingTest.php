<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\ListingStatus;
use App\Enums\Role;
use App\Enums\StaffRole;
use App\Models\Admin;
use App\Models\Listing;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The limits admins set in the panel's settings: one-time codes, requests per minute and how long a
 * sign-in lasts.
 */
class SecuritySettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admins_see_and_change_every_limit(): void
    {
        Sanctum::actingAs(Admin::factory()->create()->assignRole(StaffRole::Admin->value));
        $limits = [
            'otp_ttl_minutes' => 10,
            'otp_resend_seconds' => 120,
            'otp_max_attempts' => 3,
            'login_attempts_per_minute' => 20,
            'otp_requests_per_minute' => 8,
            'comments_per_minute' => 2,
            'session_hours' => 48,
        ];

        $this->getJson(route('v1.admin.settings.show'))
            ->assertJsonPath('data.otp_ttl_minutes', 5)
            ->assertJsonPath('data.otp_resend_seconds', 60)
            ->assertJsonPath('data.session_hours', 12);

        $response = $this->putJson(route('v1.admin.settings.update'), $limits)->assertOk();
        foreach ($limits as $key => $value) {
            $response->assertJsonPath("data.{$key}", $value);
        }
    }

    public function test_settings_added_in_a_release_show_their_defaults_despite_the_cache(): void
    {
        // what an older release left in the cache: saved values only for the settings it knew
        Setting::put(['otp_max_sends' => 3]);
        Setting::values();

        $this->assertSame(3, Setting::integer('otp_max_sends'));
        $this->assertSame(12, Setting::integer('session_hours'));
        $this->assertSame(60, Setting::integer('otp_resend_seconds'));
    }

    public function test_limits_are_validated_in_persian(): void
    {
        Sanctum::actingAs(Admin::factory()->create()->assignRole(StaffRole::Admin->value));

        $this->putJson(route('v1.admin.settings.update'), ['otp_resend_seconds' => 5, 'session_hours' => 0])
            ->assertJsonValidationErrors([
                'otp_resend_seconds' => 'فاصله ارسال دوباره کد باید عددی بین ۱۰ تا ۶۰۰ ثانیه باشد.',
                'session_hours' => 'مدت نشست ورود باید عددی بین ۱ تا ۷۲۰ ساعت باشد.',
            ]);
    }

    public function test_the_login_limit_follows_the_setting(): void
    {
        Setting::put(['login_attempts_per_minute' => 2]);
        $attempt = fn () => $this->postJson(route('v1.auth.login', 'member'), ['email' => 'nobody@example.com', 'password' => 'wrong']);

        $attempt()->assertUnprocessable();
        $attempt()->assertUnprocessable();
        $attempt()->assertTooManyRequests()->assertJsonPath('message', 'تعداد تلاش‌های ورود زیاد است. یک دقیقه دیگر دوباره امتحان کنید.');

        $this->travel(61)->seconds();
        $attempt()->assertUnprocessable();
    }

    public function test_the_code_request_limit_follows_the_setting(): void
    {
        Notification::fake();
        Setting::put(['otp_requests_per_minute' => 1]);

        $this->postJson(route('v1.auth.otp.send'), ['channel' => 'email', 'destination' => 'a@example.com', 'purpose' => 'signup'])->assertOk();
        $this->postJson(route('v1.auth.otp.send'), ['channel' => 'email', 'destination' => 'b@example.com', 'purpose' => 'signup'])
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'تعداد درخواست کد زیاد است. یک دقیقه دیگر دوباره امتحان کنید.');
    }

    public function test_the_comment_limit_follows_the_setting_per_account(): void
    {
        Setting::put(['comments_per_minute' => 1]);
        $listing = Listing::factory()->status(ListingStatus::Published)->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(route('v1.apps.comments.store', $listing), ['body' => 'نظر اول'])->assertCreated();
        $this->postJson(route('v1.apps.comments.store', $listing), ['body' => 'نظر دوم'])->assertTooManyRequests();

        // counted per account, so someone else on the same network can still comment
        Sanctum::actingAs(User::factory()->create());
        $this->postJson(route('v1.apps.comments.store', $listing), ['body' => 'نظر دیگری'])->assertCreated();
    }

    public function test_code_timing_follows_the_settings(): void
    {
        Notification::fake();
        Setting::put(['otp_resend_seconds' => 90, 'otp_ttl_minutes' => 10]);

        $this->postJson(route('v1.auth.otp.send'), ['channel' => 'email', 'destination' => 'a@example.com', 'purpose' => 'signup'])
            ->assertOk()
            ->assertJsonPath('resend_in', 90)
            ->assertJsonPath('expires_in', 600);
    }

    public function test_sign_ins_last_as_long_as_the_setting_says(): void
    {
        $this->freezeSecond();
        Setting::put(['session_hours' => 48]);
        $user = User::factory()->create()->assignRole(Role::Member->value);
        $admin = Admin::factory()->create()->assignRole(StaffRole::Admin->value);

        $this->postJson(route('v1.auth.login', 'member'), ['email' => $user->email, 'password' => 'password'])
            ->assertJsonPath('expires_at', now()->addHours(48)->toIso8601String());
        $this->postJson(route('v1.admin.auth.login'), ['email' => $admin->email, 'password' => 'password'])
            ->assertJsonPath('expires_at', now()->addHours(48)->toIso8601String());
    }
}
