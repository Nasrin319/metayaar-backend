<?php

namespace Tests\Unit;

use App\Support\ApkManifest;
use App\Support\FileChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class ApkManifestTest extends TestCase
{
    private const NAME = 0x01010003;

    private const LAUNCH_MODE = 0x0101001D;

    private const ORIENTATION = 0x0101001E;

    private const TEST_ONLY = 0x01010272;

    /**
     * A binary manifest with a main activity, built the way Android's tools lay it out.
     */
    private function manifest(?int $launchMode, bool $testOnly = false): string
    {
        $strings = ['name', 'launchMode', 'screenOrientation', 'testOnly', 'package', 'manifest', 'application', 'activity', 'action', 'category',
            'com.example.game', 'android.intent.action.MAIN', 'android.intent.category.LAUNCHER'];
        $offsets = '';
        $data = '';
        foreach ($strings as $string) {
            $offsets .= pack('V', strlen($data));
            $length = strlen($string);
            $data .= chr($length).chr($length).$string."\0";
        }
        $data .= str_repeat("\0", (4 - strlen($data) % 4) % 4);
        $header = 28 + strlen($offsets);
        $pool = pack('vvVVVVVV', 0x0001, 28, $header + strlen($data), count($strings), 0, 0x100, $header, 0).$offsets.$data;
        $map = pack('vvVVVVV', 0x0180, 8, 24, self::NAME, self::LAUNCH_MODE, self::ORIENTATION, self::TEST_ONLY);

        $element = function (int $tag, array $attributes): string {
            $body = pack('VVvvvvvv', 0xFFFFFFFF, $tag, 20, 20, count($attributes), 0, 0, 0);
            foreach ($attributes as [$name, $type, $value]) {
                $body .= pack('VVVvCCV', 0xFFFFFFFF, $name, $type === 3 ? $value : 0xFFFFFFFF, 8, 0, $type, $value);
            }

            return pack('vvVVV', 0x0102, 16, 16 + strlen($body), 1, 0xFFFFFFFF).$body;
        };

        $activity = $launchMode === null ? [] : [[1, 16, $launchMode]];
        $elements = $element(5, [[4, 3, 10]])
            .$element(6, $testOnly ? [[3, 18, 1]] : [])
            .$element(7, [...$activity, [2, 16, 0]])
            .$element(8, [[0, 3, 11]])
            .$element(9, [[0, 3, 12]]);

        $body = $pool.$map.$elements;

        return pack('vvV', 0x0003, 8, 8 + strlen($body)).$body;
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function apk(string $manifest, array $extra = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'apk');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('AndroidManifest.xml', $manifest);
        foreach ($extra as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    /**
     * @return array<string, array{?int, string}>
     */
    public static function launchModes(): array
    {
        return [
            'default is standard' => [null, 'standard'],
            'singleTop' => [1, 'singleTop'],
            'singleTask' => [2, 'singleTask'],
            'singleInstance' => [3, 'singleInstance'],
        ];
    }

    #[DataProvider('launchModes')]
    public function test_it_reads_the_main_activity(?int $mode, string $expected): void
    {
        $manifest = ApkManifest::parse($this->manifest($mode));

        $this->assertSame('com.example.game', $manifest['package']);
        $this->assertSame($expected, $manifest['launch_mode']);
        $this->assertTrue($manifest['landscape']);
    }

    public function test_only_single_task_passes_without_a_launch_mode_warning(): void
    {
        $task = FileChecks::apk($this->apk($this->manifest(2)), null);
        $top = FileChecks::apk($this->apk($this->manifest(1)), null);

        $this->assertSame('ok', $task['findings'][0]['level']);
        $this->assertSame('warning', $top['findings'][0]['level']);
        $this->assertStringContainsString('singleTop', $top['findings'][0]['message']);
        $this->assertNull(FileChecks::blockingMessage($top));
    }

    public function test_test_only_builds_and_builds_without_arm64_are_blocked(): void
    {
        $this->assertNotNull(FileChecks::blockingMessage(FileChecks::apk($this->apk($this->manifest(2, testOnly: true)), null)));
        $this->assertNotNull(FileChecks::blockingMessage(FileChecks::apk($this->apk($this->manifest(2), ['lib/armeabi-v7a/libgame.so' => 'x']), null)));
        $this->assertNull(FileChecks::blockingMessage(FileChecks::apk($this->apk($this->manifest(2), ['lib/arm64-v8a/libgame.so' => 'x']), null)));
    }

    public function test_a_package_name_that_differs_from_the_listing_is_a_warning(): void
    {
        $report = FileChecks::apk($this->apk($this->manifest(2)), 'com.other.app');

        $this->assertStringContainsString('com.other.app', $report['findings'][array_key_last($report['findings'])]['message']);
    }

    public function test_an_unreadable_file_is_a_warning_for_the_reviewer_not_a_crash(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'apk');
        file_put_contents($path, 'PK\x03\x04 not really a zip');

        $this->assertSame('warning', FileChecks::apk($path, null)['findings'][0]['level']);
        $this->assertSame('warning', FileChecks::apk($this->apk('garbage'), null)['findings'][0]['level']);
    }
}
