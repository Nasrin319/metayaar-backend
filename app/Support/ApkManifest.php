<?php

namespace App\Support;

/**
 * Reads the settings a store cares about out of an APK's binary AndroidManifest.xml.
 */
class ApkManifest
{
    private const XML = 0x0003;

    private const STRING_POOL = 0x0001;

    private const XML_RESOURCE_MAP = 0x0180;

    private const XML_START_ELEMENT = 0x0102;

    private const VALUE_STRING = 0x03;

    private const VALUE_REFERENCE = 0x01;

    /** Android's resource ids of the attributes read here. */
    private const ATTRIBUTES = [
        0x01010003 => 'name',
        0x0101000F => 'debuggable',
        0x0101001D => 'launchMode',
        0x0101001E => 'screenOrientation',
        0x0101020C => 'minSdkVersion',
        0x01010270 => 'targetSdkVersion',
        0x0101021B => 'versionCode',
        0x0101021C => 'versionName',
        0x01010272 => 'testOnly',
    ];

    private const LAUNCH_MODES = ['standard', 'singleTop', 'singleTask', 'singleInstance', 'singleInstancePerTask'];

    /** Values of android:screenOrientation that show the app sideways, as a headset expects. */
    private const LANDSCAPE_ORIENTATIONS = [0, 6, 8, 11];

    /** Marks an activity as an immersive app in Meta's launcher. */
    private const VR_CATEGORY = 'com.oculus.intent.category.VR';

    /**
     * The manifest's settings, read from its main activity. Null when it isn't a binary manifest.
     *
     * @return array{package: ?string, version_code: ?int, version_name: ?string, min_sdk: ?int, target_sdk: ?int, debuggable: bool, test_only: bool, launch_mode: ?string, landscape: ?bool, vr: bool}|null
     */
    public static function parse(string $xml): ?array
    {
        if (strlen($xml) < 8 || ApkIcon::u16($xml, 0) !== self::XML) {
            return null;
        }

        $result = [
            'package' => null, 'version_code' => null, 'version_name' => null, 'min_sdk' => null, 'target_sdk' => null,
            'debuggable' => false, 'test_only' => false, 'launch_mode' => null, 'landscape' => null, 'vr' => false,
        ];
        $pool = null;
        $map = [];
        $activity = null;
        $activities = [];
        $offset = ApkIcon::u16($xml, 2);

        while ($offset + 8 <= strlen($xml)) {
            $type = ApkIcon::u16($xml, $offset);
            $headerSize = ApkIcon::u16($xml, $offset + 2);
            $size = ApkIcon::u32($xml, $offset + 4);
            if ($size < 8) {
                break;
            }

            if ($type === self::STRING_POOL) {
                $pool = $offset;
            } elseif ($type === self::XML_RESOURCE_MAP) {
                for ($i = 0; $i < ($size - $headerSize) / 4; $i++) {
                    $map[] = ApkIcon::u32($xml, $offset + $headerSize + $i * 4);
                }
            } elseif ($type === self::XML_START_ELEMENT && $pool !== null) {
                $element = $offset + $headerSize;
                $tag = ApkIcon::string($xml, $pool, ApkIcon::u32($xml, $element + 4));
                $attributes = self::attributes($xml, $element, $pool, $map);

                match ($tag) {
                    'manifest' => $result = [
                        ...$result,
                        'package' => is_string($attributes['package'] ?? null) ? $attributes['package'] : null,
                        'version_code' => is_int($attributes['versionCode'] ?? null) ? $attributes['versionCode'] : null,
                        'version_name' => is_string($attributes['versionName'] ?? null) ? $attributes['versionName'] : null,
                    ],
                    'uses-sdk' => $result = [
                        ...$result,
                        'min_sdk' => is_int($attributes['minSdkVersion'] ?? null) ? $attributes['minSdkVersion'] : null,
                        'target_sdk' => is_int($attributes['targetSdkVersion'] ?? null) ? $attributes['targetSdkVersion'] : null,
                    ],
                    'application' => $result = [
                        ...$result,
                        'debuggable' => ! empty($attributes['debuggable']),
                        'test_only' => ! empty($attributes['testOnly']),
                    ],
                    default => null,
                };

                if ($tag === 'activity') {
                    $activity = count($activities);
                    $activities[] = ['attributes' => $attributes, 'main' => false, 'launcher' => false, 'vr' => false];
                } elseif ($activity !== null && $tag === 'action' && ($attributes['name'] ?? null) === 'android.intent.action.MAIN') {
                    $activities[$activity]['main'] = true;
                } elseif ($activity !== null && $tag === 'category') {
                    $name = $attributes['name'] ?? null;
                    if ($name === 'android.intent.category.LAUNCHER') {
                        $activities[$activity]['launcher'] = true;
                    } elseif ($name === self::VR_CATEGORY) {
                        $activities[$activity]['vr'] = true;
                    }
                }
            }

            $offset += $size;
        }

        $main = self::mainActivity($activities);
        if ($main !== null) {
            $mode = $main['attributes']['launchMode'] ?? 0;
            $orientation = $main['attributes']['screenOrientation'] ?? null;
            $result['launch_mode'] = is_int($mode) ? (self::LAUNCH_MODES[$mode] ?? null) : null;
            $result['landscape'] = is_int($orientation) ? in_array($orientation, self::LANDSCAPE_ORIENTATIONS, true) : false;
            $result['vr'] = $main['vr'];
        }

        return $result;
    }

    /**
     * @param  list<array{attributes: array<string, mixed>, main: bool, launcher: bool, vr: bool}>  $activities
     * @return array{attributes: array<string, mixed>, main: bool, launcher: bool, vr: bool}|null
     */
    private static function mainActivity(array $activities): ?array
    {
        foreach ($activities as $activity) {
            if ($activity['main'] && ($activity['launcher'] || $activity['vr'])) {
                return $activity;
            }
        }

        foreach ($activities as $activity) {
            if ($activity['main']) {
                return $activity;
            }
        }

        return null;
    }

    /**
     * An element's known attributes: strings as text, other values as integers, references left out.
     *
     * @param  list<int>  $map
     * @return array<string, string|int>
     */
    private static function attributes(string $xml, int $element, int $pool, array $map): array
    {
        $start = ApkIcon::u16($xml, $element + 8);
        $size = ApkIcon::u16($xml, $element + 10);
        $count = ApkIcon::u16($xml, $element + 12);
        $attributes = [];

        for ($i = 0; $i < $count; $i++) {
            $attribute = $element + $start + $i * $size;
            $index = ApkIcon::u32($xml, $attribute + 4);
            $name = isset($map[$index]) ? (self::ATTRIBUTES[$map[$index]] ?? null) : ApkIcon::string($xml, $pool, $index);
            if ($name === null) {
                continue;
            }

            $valueType = ord($xml[$attribute + 15]);
            $data = ApkIcon::u32($xml, $attribute + 16);
            if ($valueType === self::VALUE_STRING) {
                $attributes[$name] = (string) ApkIcon::string($xml, $pool, $data);
            } elseif ($valueType !== self::VALUE_REFERENCE) {
                $attributes[$name] = $data;
            }
        }

        return $attributes;
    }
}
