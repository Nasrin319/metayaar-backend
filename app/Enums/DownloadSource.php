<?php

namespace App\Enums;

/**
 * Where an APK download came from.
 */
enum DownloadSource: string
{
    /** The "Download APK" button in a browser. */
    case Web = 'web';
    /** "Install on headset" in Metayaar Desktop (Electron). */
    case Desktop = 'desktop';
    /** The Metayaar app on the headset itself. */
    case Headset = 'headset';

    /**
     * Tell the clients apart by user agent: the headset app adds "MetayaarQuest/<version>" to its
     * WebView's, and the desktop app is Electron.
     */
    public static function fromUserAgent(?string $userAgent): self
    {
        return match (true) {
            $userAgent !== null && str_contains($userAgent, 'MetayaarQuest') => self::Headset,
            $userAgent !== null && str_contains($userAgent, 'Electron') => self::Desktop,
            default => self::Web,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
