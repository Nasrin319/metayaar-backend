<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A person's national code (کد ملی, 10 digits with a check digit), or for organizations their
 * national ID (شناسه ملی, 11 digits).
 */
class IranianNationalId implements ValidationRule
{
    public function __construct(public bool $organization = false) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->organization) {
            if (! is_string($value) || ! preg_match('/^\d{11}$/', $value)) {
                $fail('شناسه ملی سازمان باید ۱۱ رقم باشد.');
            }

            return;
        }

        if (! is_string($value) || ! self::isValidNationalCode($value)) {
            $fail('کد ملی معتبر نیست.');
        }
    }

    public static function isValidNationalCode(string $code): bool
    {
        if (! preg_match('/^\d{10}$/', $code) || preg_match('/^(\d)\1{9}$/', $code)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $code[$i] * (10 - $i);
        }
        $remainder = $sum % 11;
        $check = (int) $code[9];

        return $remainder < 2 ? $check === $remainder : $check === 11 - $remainder;
    }
}
