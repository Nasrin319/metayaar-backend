<?php

namespace App\Enums;

/**
 * Kinds of external links shown on a listing's store page.
 */
enum LinkType: string
{
    case Website = 'website';
    case Discord = 'discord';
    case Telegram = 'telegram';
    case Instagram = 'instagram';
    case X = 'x';
    case YouTube = 'youtube';
    case GitHub = 'github';
    case Steam = 'steam';
    case Itch = 'itch';
    case Patreon = 'patreon';
    case Donate = 'donate';
}
