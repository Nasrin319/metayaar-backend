<?php

namespace App\Support\Sms;

/**
 * Sends one-time codes by SMS. Pick the implementation with SMS_DRIVER (see config/services.php).
 */
interface SmsSender
{
    /**
     * Send the code to the 09xxxxxxxxx mobile number. Throws when the provider rejects it.
     */
    public function sendOtp(string $mobile, string $code): void;
}
