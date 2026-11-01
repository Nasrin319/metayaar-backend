<?php

namespace App\Support\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Writes codes to the log instead of sending them, for local development.
 */
class LogSmsSender implements SmsSender
{
    public function sendOtp(string $mobile, string $code): void
    {
        Log::info("SMS one-time code for {$mobile}: {$code}");
    }
}
