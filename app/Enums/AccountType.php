<?php

namespace App\Enums;

/**
 * Whether a website account belongs to a person or an organization. It is separate from Role: an
 * organization can be a player, a developer, or both. Organization accounts keep their details in
 * App\Models\Organization, and listings can later be limited to them.
 */
enum AccountType: string
{
    case Individual = 'individual';
    case Organization = 'organization';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
