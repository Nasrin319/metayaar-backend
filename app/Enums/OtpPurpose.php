<?php

namespace App\Enums;

/**
 * What a one-time code is for. A code only works for the purpose it was sent for.
 */
enum OtpPurpose: string
{
    /** Prove the email and the mobile before the account is created. */
    case Signup = 'signup';
    /** Sign in without a password. */
    case Login = 'login';
    /** Set a new password without the old one. */
    case ResetPassword = 'reset_password';
    /** Confirm the signed-in user's email, or a mobile they are adding. */
    case Verify = 'verify';

    public function label(): string
    {
        return match ($this) {
            self::Signup => 'ثبت‌نام',
            self::Login => 'ورود',
            self::ResetPassword => 'بازیابی رمز عبور',
            self::Verify => 'تأیید',
        };
    }

    /**
     * Purposes a guest can request a code for through the public endpoint.
     *
     * @return list<string>
     */
    public static function publicValues(): array
    {
        return [self::Signup->value, self::Login->value, self::ResetPassword->value];
    }
}
