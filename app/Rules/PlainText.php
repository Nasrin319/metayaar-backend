<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Text people type (names, comments, app descriptions) must be plain text: no HTML tags, no
 * script links and no invisible control characters. Persian and Latin letters, digits and normal
 * punctuation (including a lone "<", as in "3 < 5") are all fine.
 *
 * This is a second line of defense: queries are parameterized and the sites escape everything they
 * print, so such text could not run anyway, but it is refused before it is ever stored.
 */
class PlainText implements ValidationRule
{
    /**
     * An HTML or XML tag, comment or doctype: "<" followed by a letter, "/", "!" or "?".
     */
    private const TAG = '/<\s*[a-z\/!?]/i';

    /**
     * Links that run code when opened: javascript:, vbscript:, and HTML/script data: URLs.
     */
    private const SCRIPT_LINK = '/\b(?:java|vb)script\s*:|\bdata\s*:\s*(?:text\/html|[a-z]+\/(?:x-)?javascript)/i';

    /**
     * Control characters other than tab and line breaks, including the null byte.
     */
    private const CONTROL = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (preg_match(self::TAG, $value) || preg_match(self::SCRIPT_LINK, $value)) {
            $fail(':attribute نباید شامل کد یا تگ HTML (مثل <script>) باشد.');

            return;
        }

        if (preg_match(self::CONTROL, $value)) {
            $fail(':attribute شامل نویسه‌های نامعتبر است.');
        }
    }
}
