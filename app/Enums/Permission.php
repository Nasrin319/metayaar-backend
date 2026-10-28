<?php

namespace App\Enums;

/**
 * Admin panel abilities. Roles are granted these in RoleSeeder via Role::permissions().
 */
enum Permission: string
{
    case ReviewListings = 'listings.review';
    case ManageUsers = 'users.manage';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
