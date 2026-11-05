<?php

namespace App\Enums;

/**
 * How a listing is delivered to the member (spec STORE-5).
 */
enum ListingType: string
{
    case Apk = 'apk';
    case AppLab = 'app_lab';
    case WebXr = 'webxr';
    case PcVr = 'pc_vr';
    case Windows = 'windows';

    /**
     * APK and Windows listings host a file; the others point to an external page.
     */
    public function requiresExternalUrl(): bool
    {
        return ! in_array($this, [self::Apk, self::Windows], true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
