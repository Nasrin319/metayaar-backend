<?php

namespace App\Support;

use ZipArchive;

/**
 * Reads an app's launcher icon out of its APK, the way app stores show the icon of an uploaded build.
 *
 * The <application android:icon> reference in the binary AndroidManifest.xml is resolved through
 * resources.arsc to the icon's files for every screen density, and the largest PNG, WebP or JPEG wins.
 * Vector and adaptive icons are XML and can't be rasterized here; for those, a raster file with the
 * same name elsewhere in res/ is used when the APK ships one.
 */
class ApkIcon
{
    /** The resource id of the android:icon attribute. */
    private const ICON_ATTRIBUTE = 0x01010002;

    private const XML = 0x0003;

    private const STRING_POOL = 0x0001;

    private const XML_RESOURCE_MAP = 0x0180;

    private const XML_START_ELEMENT = 0x0102;

    private const TABLE = 0x0002;

    private const TABLE_PACKAGE = 0x0200;

    private const TABLE_TYPE = 0x0201;

    private const VALUE_REFERENCE = 0x01;

    private const VALUE_STRING = 0x03;

    /** Names launchers commonly use, tried when the manifest doesn't point at an icon. */
    private const COMMON_NAMES = ['ic_launcher', 'app_icon', 'icon'];

    /**
     * The icon's image bytes and file extension, or null when the APK has no usable raster icon.
     *
     * @return array{contents: string, extension: string}|null
     */
    public static function extract(string $apkPath): ?array
    {
        $zip = new ZipArchive;
        if ($zip->open($apkPath, ZipArchive::RDONLY) !== true) {
            return null;
        }

        try {
            $paths = [];
            $manifest = $zip->getFromName('AndroidManifest.xml');
            $table = $zip->getFromName('resources.arsc');
            $iconId = $manifest === false ? null : self::manifestIconId($manifest);

            if ($iconId !== null && $table !== false) {
                $paths = self::resourceFiles($table, $iconId);
            }

            return self::largestImage($zip, $paths)
                ?? self::largestImage($zip, self::rastersNamed($zip, $paths ? array_map(
                    fn (string $path) => pathinfo($path, PATHINFO_FILENAME),
                    $paths,
                ) : self::COMMON_NAMES));
        } finally {
            $zip->close();
        }
    }

    /**
     * The resource id the <application> element's android:icon attribute points at.
     */
    private static function manifestIconId(string $xml): ?int
    {
        if (self::u16($xml, 0) !== self::XML) {
            return null;
        }

        $stringPool = null;
        $resourceMap = [];
        $offset = self::u16($xml, 2);

        while ($offset + 8 <= strlen($xml)) {
            $type = self::u16($xml, $offset);
            $headerSize = self::u16($xml, $offset + 2);
            $size = self::u32($xml, $offset + 4);
            if ($size < 8) {
                return null;
            }

            if ($type === self::STRING_POOL) {
                $stringPool = $offset;
            } elseif ($type === self::XML_RESOURCE_MAP) {
                for ($i = 0; $i < ($size - $headerSize) / 4; $i++) {
                    $resourceMap[] = self::u32($xml, $offset + $headerSize + $i * 4);
                }
            } elseif ($type === self::XML_START_ELEMENT && $stringPool !== null) {
                $element = $offset + $headerSize;
                if (self::string($xml, $stringPool, self::u32($xml, $element + 4)) === 'application') {
                    return self::iconAttribute($xml, $element, $stringPool, $resourceMap);
                }
            }

            $offset += $size;
        }

        return null;
    }

    /**
     * @param  list<int>  $resourceMap
     */
    private static function iconAttribute(string $xml, int $element, int $stringPool, array $resourceMap): ?int
    {
        $start = self::u16($xml, $element + 8);
        $size = self::u16($xml, $element + 10);
        $count = self::u16($xml, $element + 12);

        for ($i = 0; $i < $count; $i++) {
            $attribute = $element + $start + $i * $size;
            $name = self::u32($xml, $attribute + 4);
            $isIcon = isset($resourceMap[$name])
                ? $resourceMap[$name] === self::ICON_ATTRIBUTE
                : self::string($xml, $stringPool, $name) === 'icon';

            if ($isIcon) {
                return ord($xml[$attribute + 15]) === self::VALUE_REFERENCE ? self::u32($xml, $attribute + 16) : null;
            }
        }

        return null;
    }

    /**
     * The file paths a resource points at across all its configurations, following aliases.
     *
     * @return list<string>
     */
    private static function resourceFiles(string $table, int $resourceId, int $depth = 0): array
    {
        if (self::u16($table, 0) !== self::TABLE) {
            return [];
        }

        $packageId = $resourceId >> 24;
        $typeId = ($resourceId >> 16) & 0xFF;
        $entryIndex = $resourceId & 0xFFFF;
        $globalPool = null;
        $paths = [];
        $offset = self::u16($table, 2);

        while ($offset + 8 <= strlen($table)) {
            $type = self::u16($table, $offset);
            $size = self::u32($table, $offset + 4);
            if ($size < 8) {
                break;
            }

            if ($type === self::STRING_POOL && $globalPool === null) {
                $globalPool = $offset;
            } elseif ($type === self::TABLE_PACKAGE && $globalPool !== null && self::u32($table, $offset + 8) === $packageId) {
                $end = $offset + $size;
                $chunk = $offset + self::u16($table, $offset + 2);

                while ($chunk + 8 <= $end) {
                    $chunkSize = self::u32($table, $chunk + 4);
                    if ($chunkSize < 8) {
                        break;
                    }

                    if (self::u16($table, $chunk) === self::TABLE_TYPE && ord($table[$chunk + 8]) === $typeId) {
                        [$valueType, $data] = self::entryValue($table, $chunk, $entryIndex) ?? [null, null];

                        if ($valueType === self::VALUE_STRING) {
                            $paths[] = self::string($table, $globalPool, $data);
                        } elseif ($valueType === self::VALUE_REFERENCE && $depth < 4) {
                            array_push($paths, ...self::resourceFiles($table, $data, $depth + 1));
                        }
                    }

                    $chunk += $chunkSize;
                }
            }

            $offset += $size;
        }

        return array_values(array_unique($paths));
    }

    /**
     * The [type, data] of a simple entry in a type chunk, or null when the chunk has no such entry.
     *
     * @return array{int, int}|null
     */
    private static function entryValue(string $table, int $chunk, int $entryIndex): ?array
    {
        $headerSize = self::u16($table, $chunk + 2);
        $flags = ord($table[$chunk + 9]);
        $entryCount = self::u32($table, $chunk + 12);
        $entriesStart = self::u32($table, $chunk + 16);
        $offsets = $chunk + $headerSize;
        $entryOffset = null;

        if ($flags & 0x01) {
            // sparse: sorted (index, offset / 4) pairs
            for ($i = 0; $i < $entryCount; $i++) {
                if (self::u16($table, $offsets + $i * 4) === $entryIndex) {
                    $entryOffset = self::u16($table, $offsets + $i * 4 + 2) * 4;
                    break;
                }
            }
        } elseif ($entryIndex < $entryCount && $flags & 0x02) {
            // 16-bit offsets stored as offset / 4
            $value = self::u16($table, $offsets + $entryIndex * 2);
            $entryOffset = $value === 0xFFFF ? null : $value * 4;
        } elseif ($entryIndex < $entryCount) {
            $value = self::u32($table, $offsets + $entryIndex * 4);
            $entryOffset = $value === 0xFFFFFFFF ? null : $value;
        }

        if ($entryOffset === null) {
            return null;
        }

        $entry = $chunk + $entriesStart + $entryOffset;
        $entryFlags = self::u16($table, $entry + 2);

        if ($entryFlags & 0x0001) {
            return null; // a style or other bag, not a single value
        }

        if ($entryFlags & 0x0008) {
            // compact entry: the value type sits in the flags' high byte
            return [$entryFlags >> 8, self::u32($table, $entry + 4)];
        }

        $value = $entry + self::u16($table, $entry);

        return [ord($table[$value + 3]), self::u32($table, $value + 4)];
    }

    /**
     * Raster files under res/ with any of the given names, such as res/mipmap-xxxhdpi-v4/ic_launcher.png.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function rastersNamed(ZipArchive $zip, array $names): array
    {
        $paths = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $path = (string) $zip->getNameIndex($i);
            if (str_starts_with($path, 'res/') && in_array(pathinfo($path, PATHINFO_FILENAME), $names, true)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @param  list<string>  $paths
     * @return array{contents: string, extension: string}|null
     */
    private static function largestImage(ZipArchive $zip, array $paths): ?array
    {
        $extensions = [IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_JPEG => 'jpg'];
        $best = null;
        $bestArea = 0;

        foreach ($paths as $path) {
            if (str_ends_with($path, '.xml') || str_ends_with($path, '.9.png')) {
                continue;
            }

            $contents = $zip->getFromName($path);
            $info = $contents === false ? false : @getimagesizefromstring($contents);
            if (! $info || ! isset($extensions[$info[2]]) || $bestArea >= $info[0] * $info[1]) {
                continue;
            }

            $best = ['contents' => $contents, 'extension' => $extensions[$info[2]]];
            $bestArea = $info[0] * $info[1];
        }

        return $best;
    }

    private static function string(string $data, int $pool, int $index): ?string
    {
        if ($index >= self::u32($data, $pool + 8)) {
            return null;
        }

        $headerSize = self::u16($data, $pool + 2);
        $isUtf8 = (self::u32($data, $pool + 16) & 0x100) !== 0;
        $position = $pool + self::u32($data, $pool + 20) + self::u32($data, $pool + $headerSize + $index * 4);

        if ($isUtf8) {
            // the UTF-16 length comes first and isn't needed; then the UTF-8 byte length
            $position += ord($data[$position]) & 0x80 ? 2 : 1;
            $length = ord($data[$position]);
            if ($length & 0x80) {
                $length = (($length & 0x7F) << 8) | ord($data[$position + 1]);
                $position++;
            }

            return substr($data, $position + 1, $length);
        }

        $length = self::u16($data, $position);
        $position += 2;
        if ($length & 0x8000) {
            $length = (($length & 0x7FFF) << 16) | self::u16($data, $position);
            $position += 2;
        }

        return mb_convert_encoding(substr($data, $position, $length * 2), 'UTF-8', 'UTF-16LE');
    }

    private static function u16(string $data, int $offset): int
    {
        return unpack('v', $data, $offset)[1];
    }

    private static function u32(string $data, int $offset): int
    {
        return unpack('V', $data, $offset)[1];
    }
}
