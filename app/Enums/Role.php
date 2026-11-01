<?php

namespace App\Enums;

/**
 * Roles of website accounts (the "users" table). Staff are separate Admin accounts whose roles are
 * created in the admin panel, see App\Models\Admin.
 */
enum Role: string
{
    case Player = 'player';
    case Developer = 'developer';

    /**
     * Roles that anyone can sign up for through the public register endpoint.
     *
     * @return list<self>
     */
    public static function selfRegisterable(): array
    {
        return [self::Player, self::Developer];
    }

    public function isSelfRegisterable(): bool
    {
        return in_array($this, self::selfRegisterable(), true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
