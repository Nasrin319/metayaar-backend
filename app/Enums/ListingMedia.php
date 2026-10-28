<?php

namespace App\Enums;

/**
 * The images a developer can attach to a listing for its store page.
 */
enum ListingMedia: string
{
    case Icon = 'icon';
    case TitleImage = 'title_image';
    case Cover = 'cover';
    case Screenshot = 'screenshot';

    /**
     * The smallest accepted size in pixels, as [width, height].
     *
     * @return array{int, int}
     */
    public function minimumSize(): array
    {
        return match ($this) {
            self::Icon => [256, 256],
            self::TitleImage => [256, 64],
            self::Cover, self::Screenshot => [640, 360],
        };
    }
}
