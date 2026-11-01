<?php

namespace Tests;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Store a live one-time code, as if POST auth/otp had sent it.
     */
    protected function issueOtp(OtpChannel $channel, string $destination, OtpPurpose $purpose, string $code = '123456'): OtpCode
    {
        return OtpCode::create([
            'channel' => $channel,
            'destination' => $destination,
            'purpose' => $purpose,
            'code' => $code,
            'expires_at' => now()->addMinutes(5),
        ]);
    }
}
