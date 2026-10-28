<?php

namespace App\Enums;

/**
 * Headsets and platforms a listing can declare support for.
 */
enum Device: string
{
    case Quest2 = 'quest_2';
    case Quest3 = 'quest_3';
    case Quest3S = 'quest_3s';
    case QuestPro = 'quest_pro';
    case Pico4 = 'pico_4';
    case Pico4Ultra = 'pico_4_ultra';
    case ViveFocus = 'vive_focus';
    case AppleVisionPro = 'vision_pro';
    case PcVr = 'pc_vr';
}
