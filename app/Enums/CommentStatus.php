<?php

namespace App\Enums;

/**
 * Moderation state of a comment or a developer reply. Only approved ones are shown in the store.
 */
enum CommentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
