<?php

namespace App\Support;

/**
 * Iranian mobile numbers, stored as 09xxxxxxxxx.
 */
class Mobile
{
    /**
     * Turn "+98 912 345 6789", "00989123456789", "9123456789" or Persian digits into 09123456789.
     * Input that isn't a mobile number comes back trimmed, for validation to reject.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = strtr(trim($value), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $digits = preg_replace('/[\s\-()]/', '', $digits);

        if (preg_match('/^(?:\+98|0098|98|0)?(9\d{9})$/', $digits, $match)) {
            return '0'.$match[1];
        }

        return $digits;
    }
}
