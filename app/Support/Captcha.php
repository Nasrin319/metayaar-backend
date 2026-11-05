<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * A two-step math question drawn on an image for a login form: the text is never sent, so a script has
 * to read the picture first. The answer travels encrypted in the token, so unanswered questions store
 * nothing on the server; only a used token is remembered, so it can't be used twice.
 */
class Captcha
{
    private const TTL_MINUTES = 5;

    private const WIDTH = 400;

    private const HEIGHT = 120;

    /** The image is drawn this many times larger and then scaled down, which smooths every edge. */
    private const SCALE = 2;

    /**
     * A new random question.
     *
     * @return array{token: string, image: string}
     */
    public static function create(): array
    {
        return match (random_int(0, 4)) {
            0 => self::multiplyThenAdd(),
            1 => self::multiplyThenSubtract(),
            2 => self::addThenMultiply(),
            3 => self::divideThenAdd(),
            default => self::addThenSubtract(),
        };
    }

    /**
     * @return array{token: string, image: string}
     */
    public static function issue(string $question, int $answer): array
    {
        return [
            'image' => self::draw($question),
            'token' => Crypt::encryptString(json_encode([
                'answer' => $answer,
                'expires' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
                'nonce' => Str::random(20),
            ])),
        ];
    }

    /**
     * @return array{token: string, image: string}
     */
    private static function multiplyThenAdd(): array
    {
        [$a, $b, $c] = [random_int(2, 9), random_int(2, 9), random_int(11, 50)];

        return self::issue("({$a}×{$b})+{$c}", $a * $b + $c);
    }

    /**
     * @return array{token: string, image: string}
     */
    private static function multiplyThenSubtract(): array
    {
        [$a, $b] = [random_int(3, 9), random_int(3, 9)];
        $c = random_int(2, min(30, $a * $b - 1));

        return self::issue("({$a}×{$b})−{$c}", $a * $b - $c);
    }

    /**
     * @return array{token: string, image: string}
     */
    private static function addThenMultiply(): array
    {
        [$a, $b, $c] = [random_int(2, 9), random_int(2, 9), random_int(2, 5)];

        return self::issue("({$a}+{$b})×{$c}", ($a + $b) * $c);
    }

    /**
     * @return array{token: string, image: string}
     */
    private static function divideThenAdd(): array
    {
        [$quotient, $divisor, $c] = [random_int(2, 9), random_int(2, 9), random_int(11, 50)];
        $dividend = $quotient * $divisor;

        return self::issue("({$dividend}÷{$divisor})+{$c}", $quotient + $c);
    }

    /**
     * @return array{token: string, image: string}
     */
    private static function addThenSubtract(): array
    {
        [$a, $b, $c] = [random_int(20, 60), random_int(11, 39), random_int(11, 30)];

        return self::issue("({$a}+{$b})−{$c}", $a + $b - $c);
    }

    /**
     * The question as a PNG data URI, with bent, uneven characters and lines running through them.
     */
    private static function draw(string $question): string
    {
        $width = self::WIDTH * self::SCALE;
        $height = self::HEIGHT * self::SCALE;
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 14, 16, 20));

        $palette = [[232, 235, 240], [221, 230, 90], [159, 216, 255], [255, 196, 138]];

        self::scatterDots($canvas, $palette, $width, $height);
        self::drawCharacters($canvas, $question.'=?', $palette, $width, $height);
        self::drawLines($canvas, $palette, $width, $height);

        $bent = self::bend($canvas, $width, $height);
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagecopyresampled($image, $bent, 0, 0, 0, 0, self::WIDTH, self::HEIGHT, $width, $height);

        ob_start();
        imagepng($image, null, 9);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    /**
     * @param  list<array{int, int, int}>  $palette
     */
    private static function scatterDots(\GdImage $canvas, array $palette, int $width, int $height): void
    {
        for ($i = 0; $i < 220; $i++) {
            [$r, $g, $b] = $palette[array_rand($palette)];
            $size = random_int(2, 6);
            imagefilledellipse($canvas, random_int(0, $width), random_int(0, $height), $size, $size, imagecolorallocatealpha($canvas, $r, $g, $b, random_int(50, 90)));
        }
    }

    /**
     * @param  list<array{int, int, int}>  $palette
     */
    private static function drawCharacters(\GdImage $canvas, string $text, array $palette, int $width, int $height): void
    {
        $font = resource_path('fonts/DejaVuSans-Bold.ttf');
        $characters = mb_str_split($text);
        $gap = 2 * self::SCALE;

        // as large as the question fits: short ones would otherwise leave half the image empty
        $measure = function (float $size) use ($characters, $font, $gap): array {
            return array_map(function (string $character) use ($size, $font, $gap): int {
                $box = imagettfbbox($size, 0, $font, $character);

                return $box[2] - $box[0] + $gap;
            }, $characters);
        };
        $size = 40.0 * self::SCALE;
        $total = array_sum($measure($size));
        $size = min($size, $size * $width * 0.9 / $total);
        $advances = $measure($size);

        $x = (int) (($width - array_sum($advances)) / 2);
        foreach ($characters as $i => $character) {
            [$r, $g, $b] = $palette[array_rand($palette)];
            // symbols only tilt a little: a turned × reads as + and a turned ÷ as a stray mark
            $angle = ctype_digit($character) ? random_int(-16, 16) : random_int(-5, 5);
            $y = (int) ($height / 2 + $size * 0.5 * 1.33 / 2) + random_int(-7, 7) * self::SCALE;
            imagettftext($canvas, $size, $angle, $x, $y, imagecolorallocate($canvas, $r, $g, $b), $font, $character);
            $x += $advances[$i];
        }
    }

    /**
     * Wavy lines over the text, in the same colors, so they can't be filtered out by color.
     *
     * @param  list<array{int, int, int}>  $palette
     */
    private static function drawLines(\GdImage $canvas, array $palette, int $width, int $height): void
    {
        for ($line = 0; $line < 3; $line++) {
            [$r, $g, $b] = $palette[array_rand($palette)];
            $color = imagecolorallocatealpha($canvas, $r, $g, $b, 50);
            $base = random_int((int) ($height * 0.25), (int) ($height * 0.75));
            $rise = random_int(-(int) ($height * 0.2), (int) ($height * 0.2));
            $amplitude = random_int(8, 22) * self::SCALE;
            $period = random_int(120, 260) * self::SCALE;
            $phase = random_int(0, 628) / 100;
            $thickness = 2 * self::SCALE;

            for ($x = 0; $x < $width; $x += 2) {
                $y = $base + $rise * $x / $width + $amplitude * sin(2 * M_PI * $x / $period + $phase);
                imagefilledellipse($canvas, $x, (int) $y, $thickness, $thickness, $color);
            }
        }
    }

    /**
     * Shifts each column up or down along a wave, which bends the characters and the lines together.
     */
    private static function bend(\GdImage $canvas, int $width, int $height): \GdImage
    {
        $bent = imagecreatetruecolor($width, $height);
        imagefill($bent, 0, 0, imagecolorallocate($bent, 14, 16, 20));

        $amplitude = random_int(3, 5) * self::SCALE;
        $period = random_int(150, 240) * self::SCALE;
        $phase = random_int(0, 628) / 100;

        for ($x = 0; $x < $width; $x++) {
            imagecopy($bent, $canvas, $x, (int) ($amplitude * sin(2 * M_PI * $x / $period + $phase)), $x, 0, 1, $height);
        }

        return $bent;
    }

    /**
     * Whether the answer is right. Any try uses the token up, so each question gets one guess.
     */
    public static function check(string $token, string $answer): bool
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return false;
        }

        if ($data['expires'] < now()->getTimestamp()) {
            return false;
        }
        if (! Cache::add("captcha-used:{$data['nonce']}", true, now()->addMinutes(self::TTL_MINUTES + 1))) {
            return false;
        }

        return trim($answer) === (string) $data['answer'];
    }
}
