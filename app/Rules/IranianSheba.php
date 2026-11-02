<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * An Iranian IBAN (شماره شبا): "IR" and 24 digits whose ISO 13616 mod-97 checksum holds.
 * Expects the value normalized by PayoutAccountRequest (uppercase, no spaces, Latin digits).
 */
class IranianSheba implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^IR\d{24}$/', $value) || ! self::checksumHolds($value)) {
            $fail('شماره شبا معتبر نیست. شبا با IR و ۲۴ رقم شروع می‌شود.');
        }
    }

    public static function checksumHolds(string $iban): bool
    {
        // move the country code and check digits to the end, and spell letters as numbers (A=10 … Z=35)
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = preg_replace_callback('/[A-Z]/', fn (array $letter): string => (string) (ord($letter[0]) - 55), $rearranged);

        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder === 1;
    }
}
