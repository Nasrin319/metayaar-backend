<?php

namespace App\Enums;

/**
 * How a listing is delivered to the player (spec STORE-5).
 */
enum ListingType: string
{
    case Apk = 'apk';
    case AppLab = 'app_lab';
    case WebXr = 'webxr';
    case PcVr = 'pc_vr';

    /**
     * Every type except APK points to an external page instead of a hosted file.
     */
    public function requiresExternalUrl(): bool
    {
        return $this !== self::Apk;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
