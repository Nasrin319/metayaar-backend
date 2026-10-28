<?php

namespace App\Enums;

enum Role: string
{
    case Player = 'player';
    case Developer = 'developer';
    case Moderator = 'moderator';
    case Curator = 'curator';
    case Admin = 'admin';

    /**
     * Roles that anyone can sign up for through the public register endpoint.
     * Staff roles (moderator, curator, admin) are assigned by an admin.
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
     * Admin panel abilities this role holds. Admins manage everything, moderators review apps.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::Moderator => [Permission::ReviewListings],
            default => [],
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
