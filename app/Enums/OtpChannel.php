<?php

namespace App\Enums;

/**
 * Where a one-time code is sent.
 */
enum OtpChannel: string
{
    case Email = 'email';
    case Mobile = 'mobile';

    /**
     * Validation rules for a destination on this channel. Mobiles are normalized first, see
     * App\Support\Mobile.
     *
     * @return list<string>
     */
    public function destinationRules(): array
    {
        return match ($this) {
            self::Email => ['required', 'string', 'lowercase', 'email', 'max:255'],
            self::Mobile => ['required', 'string', 'regex:/^09\d{9}$/'],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
