<?php

namespace App\Enums;

/**
 * How intense the experience feels, from motion-sickness-safe to only for experienced users.
 */
enum Comfort: string
{
    case Comfortable = 'comfortable';
    case Moderate = 'moderate';
    case Average = 'average';
    case Exciting = 'exciting';
    case Intense = 'intense';
}
