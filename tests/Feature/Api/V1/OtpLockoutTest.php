<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\OtpLockout;
use App\Models\Setting;
use App\Support\Otp;
use App\Support\Sms\SmsSender;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OtpLockoutTest extends TestCase
{
    use RefreshDatabase;

    private Otp $otp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SmsSender::class, new class implements SmsSender
        {
            public function sendOtp(string $mobile, string $code): void {}
        });
        $this->otp = $this->app->make(Otp::class);
    }

    /**
     * Send a code, past the resend wait of the previous one.
     */
    private function send(string $mobile = '09123456789', OtpPurpose $purpose = OtpPurpose::Login): void
    {
        $this->travel(Otp::RESEND_SECONDS + 1)->seconds();
        $this->otp->send(OtpChannel::Mobile, $mobile, $purpose);
    }

    private function assertLockedOut(string $mobile = '09123456789'): void
    {
        try {
            $this->send($mobile);
            $this->fail('The code was sent although the mobile is locked out.');
        } catch (ValidationException $e) {
            $this->assertSame(429, $e->status);
            $this->assertStringContainsString('ارسال کد تا', $e->errors()['destination'][0]);
        }
    }

    public function test_a_mobile_is_locked_out_after_the_maximum_codes_without_signing_in(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->send();
        }

        $this->assertLockedOut();
        $this->assertNotNull(OtpLockout::first()->locked_until);

        // other numbers are not affected
        $this->send('09120000000');
    }

    public function test_the_lockout_counts_every_purpose_together(): void
    {
        $this->send(purpose: OtpPurpose::Login);
        $this->send(purpose: OtpPurpose::ResetPassword);
        $this->send(purpose: OtpPurpose::Login);
        $this->send(purpose: OtpPurpose::ResetPassword);
        $this->send(purpose: OtpPurpose::Login);

        $this->assertLockedOut();
    }

    public function test_the_lockout_ends_after_the_set_hours(): void
    {
        Setting::put(['otp_max_sends' => 2, 'otp_lockout_hours' => 3]);
        $this->send();
        $this->send();
        $this->assertLockedOut();

        $this->travel(2)->hours();
        $this->assertLockedOut();

        $this->travel(1)->hours();
        $this->send();
    }

    public function test_signing_in_with_a_code_clears_the_count(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->send();
        }
        $code = '123456';
        OtpCode::query()->latest('id')->first()->update(['code' => $code]);

        $this->assertTrue($this->otp->verify(OtpChannel::Mobile, '09123456789', OtpPurpose::Login, $code));
        $this->assertSame(0, OtpLockout::count());

        for ($i = 0; $i < 4; $i++) {
            $this->send();
        }
    }

    public function test_the_last_code_still_works_after_the_lockout_starts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->send();
        }
        OtpCode::query()->latest('id')->first()->update(['code' => '654321']);

        $this->assertTrue($this->otp->verify(OtpChannel::Mobile, '09123456789', OtpPurpose::Login, '654321'));
        $this->send();
    }

    public function test_the_api_answers_429_when_locked_out(): void
    {
        $this->seed(RoleSeeder::class);
        Setting::put(['otp_max_sends' => 1]);

        $this->postJson(route('v1.auth.otp.send'), ['channel' => 'mobile', 'destination' => '09123456789', 'purpose' => 'signup'])->assertOk();
        $this->travel(Otp::RESEND_SECONDS + 1)->seconds();
        $this->postJson(route('v1.auth.otp.send'), ['channel' => 'mobile', 'destination' => '09123456789', 'purpose' => 'signup'])
            ->assertStatus(429)
            ->assertJsonValidationErrors('destination');
    }
}
