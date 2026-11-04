<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * Turns Persian and Arabic digits in request input into 0-9, for every client. Passwords stay exactly
 * as typed, or existing ones would stop matching.
 */
class ConvertDigits extends TransformsRequest
{
    private const array DIGITS = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    private const array EXCEPT = ['current_password', 'password', 'password_confirmation'];

    /**
     * Transform the given value.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function transform($key, $value)
    {
        if (! is_string($value) || in_array($key, self::EXCEPT, true)) {
            return $value;
        }

        return strtr($value, self::DIGITS);
    }
}
