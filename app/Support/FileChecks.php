<?php

namespace App\Support;

use Throwable;
use ZipArchive;

/**
 * Checks an uploaded app file and reports it in Persian for the developer and the reviewer. An "error"
 * finding stops the upload, because the file can't work; "warning" and "ok" are advice.
 *
 * These checks read the file's structure only. They can't tell whether a Windows program is safe.
 */
class FileChecks
{
    private const NATIVE_ABI = 'arm64-v8a';

    /** The lowest Android version current Meta Quest headsets accept. */
    private const MIN_SDK = 29;

    private const EXE_SCAN_BYTES = 5 * 1024 * 1024;

    private const MACHINES = [0x8664 => '۶۴ بیتی (x64)', 0x014C => '۳۲ بیتی (x86)', 0xAA64 => 'ARM64'];

    /**
     * @return array{kind: string, facts: list<array{label: string, value: string}>, findings: list<array{level: string, message: string}>, package?: ?string, version_code?: ?int, version_name?: ?string}
     */
    public static function apk(string $path, ?string $expectedPackage): array
    {
        $unreadable = [
            'kind' => 'apk',
            'facts' => [],
            'findings' => [self::warning('تنظیمات داخل فایل خوانده نشد. داور باید فایل را روی هدست امتحان کند.')],
        ];

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return $unreadable;
        }

        try {
            $contents = $zip->getFromName('AndroidManifest.xml');
            $abis = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (preg_match('#^lib/([^/]+)/#', (string) $zip->getNameIndex($i), $match)) {
                    $abis[$match[1]] = true;
                }
            }
        } finally {
            $zip->close();
        }

        try {
            $manifest = $contents === false ? null : ApkManifest::parse($contents);
        } catch (Throwable $e) {
            report($e);
            $manifest = null;
        }
        if ($manifest === null) {
            return $unreadable;
        }

        $abis = array_keys($abis);
        $findings = [];

        if ($manifest['test_only']) {
            $findings[] = self::error('این فایل نسخه آزمایشی (testOnly) است و با نصب عادی نصب نمی‌شود. نسخه Release بسازید.');
        }
        if ($abis && ! in_array(self::NATIVE_ABI, $abis, true)) {
            $findings[] = self::error('این فایل نسخه ARM64 ندارد و روی هدست اجرا نمی‌شود. در Unity گزینه‌های IL2CPP و ARM64 را روشن کنید.');
        } elseif ($abis) {
            $findings[] = self::ok('نسخه ARM64 دارد.');
        }

        $findings[] = match ($manifest['launch_mode']) {
            'singleTask' => self::ok('Launch Mode روی singleTask است؛ مناسب اپ VR.'),
            null => self::warning('Launch Mode خوانده نشد. برای اپ VR مقدار singleTask پیشنهاد می‌شود.'),
            default => self::warning("Launch Mode روی {$manifest['launch_mode']} است. برای اپ VR مقدار singleTask پیشنهاد می‌شود تا با باز کردن دوباره، اپ دو بار اجرا نشود."),
        };

        if ($manifest['landscape'] === false) {
            $findings[] = self::warning('صفحه اصلی اپ روی حالت افقی (landscape) تنظیم نشده است. اپ VR باید افقی باشد.');
        }
        if ($manifest['min_sdk'] !== null && $manifest['min_sdk'] < self::MIN_SDK) {
            $findings[] = self::warning('Minimum API Level کمتر از '.self::MIN_SDK.' است. هدست‌های فعلی Meta Quest به API '.self::MIN_SDK.' یا بالاتر نیاز دارند.');
        }
        if ($manifest['debuggable']) {
            $findings[] = self::warning('این فایل نسخه Debug است. برای انتشار، نسخه Release بسازید.');
        }
        if ($expectedPackage && $manifest['package'] && $expectedPackage !== $manifest['package']) {
            $findings[] = self::warning("نام بسته فایل ({$manifest['package']}) با نام بسته ثبت‌شده ({$expectedPackage}) یکی نیست.");
        }

        return [
            'kind' => 'apk',
            'package' => $manifest['package'],
            'version_code' => $manifest['version_code'],
            'version_name' => $manifest['version_name'],
            'facts' => array_values(array_filter([
                self::fact('نام بسته', $manifest['package']),
                self::fact('نسخه', $manifest['version_name']),
                self::fact('Launch Mode', $manifest['launch_mode']),
                self::fact('Minimum API', $manifest['min_sdk']),
                self::fact('Target API', $manifest['target_sdk']),
                self::fact('معماری', $abis ? implode('، ', $abis) : null),
                self::fact('دسته VR متا', $manifest['vr'] ? 'دارد' : 'ندارد'),
            ])),
            'findings' => $findings,
        ];
    }

    /**
     * @return array{kind: string, facts: list<array{label: string, value: string}>, findings: list<array{level: string, message: string}>}
     */
    public static function windows(string $path, string $extension): array
    {
        return $extension === 'exe' ? self::exe($path) : self::zip($path);
    }

    /**
     * @return array{kind: string, facts: list<array{label: string, value: string}>, findings: list<array{level: string, message: string}>}
     */
    private static function exe(string $path): array
    {
        $invalid = fn () => [
            'kind' => 'exe',
            'facts' => [],
            'findings' => [self::error('این فایل یک برنامه معتبر ویندوز (exe) نیست.')],
        ];

        $handle = fopen($path, 'rb');
        // large enough for a Unity player's small exe, which is all the name search below needs
        $header = (string) fread($handle, self::EXE_SCAN_BYTES);
        fclose($handle);

        if (strlen($header) < 64 || ! str_starts_with($header, 'MZ')) {
            return $invalid();
        }

        $pe = ApkIcon::u32($header, 0x3C);
        if ($pe + 26 > strlen($header) || substr($header, $pe, 4) !== "PE\0\0") {
            return $invalid();
        }

        $characteristics = ApkIcon::u16($header, $pe + 22);
        if ($characteristics & 0x2000) {
            return ['kind' => 'exe', 'facts' => [], 'findings' => [self::error('این فایل کتابخانه (DLL) است، نه برنامه قابل اجرا.')]];
        }

        // Unity's exe only starts UnityPlayer.dll and the _Data folder, so alone it can't run. A packed installer doesn't show this name.
        if (stripos($header, 'UnityPlayer.dll') !== false && filesize($path) <= self::EXE_SCAN_BYTES) {
            return ['kind' => 'exe', 'facts' => [], 'findings' => [self::error('این exe خروجی Unity است و تنها اجرا نمی‌شود. کل پوشه خروجی Build را zip کنید و فایل zip را آپلود کنید.')]];
        }

        $machine = self::MACHINES[ApkIcon::u16($header, $pe + 4)] ?? 'نامشخص';
        $optional = $pe + 24;
        $is64 = $optional + 2 <= strlen($header) && ApkIcon::u16($header, $optional) === 0x20B;
        $certificate = $optional + ($is64 ? 112 : 96) + 4 * 8 + 4;
        $signed = $certificate + 4 <= strlen($header) && ApkIcon::u32($header, $certificate) > 0;

        return [
            'kind' => 'exe',
            'facts' => [
                ['label' => 'نوع فایل', 'value' => 'برنامه یا نصب‌کننده exe'],
                ['label' => 'معماری', 'value' => $machine],
                ['label' => 'امضای دیجیتال', 'value' => $signed ? 'دارد (اعتبار آن بررسی نشده)' : 'ندارد'],
            ],
            'findings' => [
                $signed
                    ? self::ok('فایل امضای دیجیتال دارد.')
                    : self::warning('فایل امضای دیجیتال ندارد؛ ویندوز هنگام اجرا به کاربر هشدار می‌دهد.'),
            ],
        ];
    }

    /**
     * @return array{kind: string, facts: list<array{label: string, value: string}>, findings: list<array{level: string, message: string}>}
     */
    private static function zip(string $path): array
    {
        $fail = fn (string $message) => ['kind' => 'zip', 'facts' => [], 'findings' => [self::error($message)]];

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return $fail('این فایل یک ZIP معتبر نیست.');
        }

        try {
            $programs = [];
            $scripts = [];
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i, ZipArchive::FL_ENC_RAW | ZipArchive::FL_UNCHANGED);
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));

                if (str_starts_with($name, '/') || preg_match('#^[a-z]:#i', $name) || in_array('..', explode('/', $name), true)) {
                    return $fail('مسیر فایل‌های داخل ZIP نامعتبر است و می‌تواند بیرون از پوشه استخراج شود.');
                }
                if (($stat['encryption_method'] ?? 0) !== 0) {
                    return $fail('ZIP رمزدار پذیرفته نمی‌شود، چون داور نمی‌تواند آن را بررسی کند.');
                }

                $names[] = strtolower($name);
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if ($extension === 'exe') {
                    $programs[] = $name;
                } elseif (in_array($extension, ['bat', 'cmd', 'ps1', 'vbs', 'js', 'msi', 'scr'], true)) {
                    $scripts[] = $name;
                }
            }
            $count = $zip->numFiles;
        } finally {
            $zip->close();
        }

        if (! $programs) {
            return $fail('داخل ZIP هیچ فایل exe پیدا نشد.');
        }

        $unity = self::isUnityBuild($names);
        if ($unity && ! self::hasUnityData($names, $programs)) {
            return $fail('این خروجی Unity ناقص است. فایل exe و پوشه همنام آن با پسوند _Data باید کنار هم در ZIP باشند.');
        }

        $findings = [self::ok($unity ? 'خروجی Unity کامل به نظر می‌رسد.' : 'داخل ZIP برنامه قابل اجرا پیدا شد.')];
        if ($unity && array_filter($names, fn (string $name) => str_contains($name, '_donotship') || str_contains($name, 'butdontshipitwithyourgame'))) {
            $findings[] = self::warning('پوشه‌های DoNotShip یا DontShip مخصوص دیباگ هستند و نباید منتشر شوند. آن‌ها را از ZIP حذف کنید.');
        }
        if ($scripts) {
            $findings[] = self::warning('ZIP شامل اسکریپت یا نصب‌کننده دیگری است؛ داور باید آن را بررسی کند: '.implode('، ', array_slice($scripts, 0, 5)));
        }

        return [
            'kind' => 'zip',
            'facts' => [
                ['label' => 'نوع فایل', 'value' => $unity ? 'خروجی Unity در ZIP' : 'نسخه فشرده ZIP'],
                ['label' => 'تعداد فایل‌ها', 'value' => (string) $count],
                ['label' => 'برنامه‌ها', 'value' => implode('، ', array_slice($programs, 0, 5))],
            ],
            'findings' => $findings,
        ];
    }

    /**
     * @param  list<string>  $names  Lowercase paths inside the zip.
     */
    private static function isUnityBuild(array $names): bool
    {
        foreach ($names as $name) {
            if ($name === 'unityplayer.dll' || str_ends_with($name, '/unityplayer.dll')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a program sits beside the data folder of the same name, as Unity needs to start.
     *
     * @param  list<string>  $names  Lowercase paths inside the zip.
     * @param  list<string>  $programs
     */
    private static function hasUnityData(array $names, array $programs): bool
    {
        foreach ($programs as $program) {
            $folder = strtolower(substr(str_replace('\\', '/', $program), 0, -4)).'_data/';
            foreach ($names as $name) {
                if (str_starts_with($name, $folder)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The first finding that stops the upload.
     *
     * @param  array{findings: list<array{level: string, message: string}>}  $report
     */
    public static function blockingMessage(array $report): ?string
    {
        foreach ($report['findings'] as $finding) {
            if ($finding['level'] === 'error') {
                return $finding['message'];
            }
        }

        return null;
    }

    /**
     * @return array{label: string, value: string}|null
     */
    private static function fact(string $label, string|int|null $value): ?array
    {
        return $value === null || $value === '' ? null : ['label' => $label, 'value' => (string) $value];
    }

    /**
     * @return array{level: string, message: string}
     */
    private static function error(string $message): array
    {
        return ['level' => 'error', 'message' => $message];
    }

    /**
     * @return array{level: string, message: string}
     */
    private static function warning(string $message): array
    {
        return ['level' => 'warning', 'message' => $message];
    }

    /**
     * @return array{level: string, message: string}
     */
    private static function ok(string $message): array
    {
        return ['level' => 'ok', 'message' => $message];
    }
}
