<?php

namespace Tests\Unit;

use App\Rules\PlainText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PlainTextRuleTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function plainText(): array
    {
        return [
            'persian' => ['بازی خیلی خوبی بود، ممنون!'],
            'latin and digits' => ['Works great on Quest 2 and Pico 4'],
            'mixed with newlines' => ["خط اول\nLine two\twith a tab"],
            'less than sign' => ['امتیاز من < 10 بود و 3<5 است'],
            'heart emoticon' => ['عالی بود <3'],
            'sql-looking words' => ["I select the best game from the store; don't drop it"],
            'a normal link' => ['Trailer: https://www.youtube.com/watch?v=abc'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function harmfulText(): array
    {
        return [
            'script tag' => ['<script>alert(1)</script>'],
            'script tag with spaces' => ['< script >alert(1)'],
            'image with handler' => ['<img src=x onerror=alert(1)>'],
            'closing tag' => ['hello </div>'],
            'html comment' => ['<!-- hidden -->'],
            'php tag' => ['<?php echo 1; ?>'],
            'svg' => ['<svg onload=alert(1)>'],
            'javascript link' => ['click javascript:alert(document.cookie)'],
            'javascript link uppercase' => ['JavaScript :alert(1)'],
            'vbscript link' => ['vbscript:msgbox(1)'],
            'html data url' => ['data:text/html;base64,PHNjcmlwdD4='],
            'null byte' => ["name\0"],
            'escape character' => ["text\x1B[31m"],
        ];
    }

    #[DataProvider('plainText')]
    public function test_plain_text_passes(string $value): void
    {
        $this->assertNull($this->failureFor($value));
    }

    #[DataProvider('harmfulText')]
    public function test_code_and_control_characters_fail(string $value): void
    {
        $this->assertNotNull($this->failureFor($value));
    }

    public function test_non_strings_are_left_to_other_rules(): void
    {
        $this->assertNull($this->failureFor(123));
        $this->assertNull($this->failureFor(null));
    }

    private function failureFor(mixed $value): ?string
    {
        $failure = null;
        (new PlainText)->validate('body', $value, function (string $message) use (&$failure): void {
            $failure = $message;
        });

        return $failure;
    }
}
