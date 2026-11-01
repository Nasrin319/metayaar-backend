<?php

namespace App\Enums;

/**
 * The staff roles every installation starts with. Admins can create more in the panel; only "admin"
 * is fixed: it always holds every permission and cannot be edited or deleted, so the panel can't lock
 * itself out.
 */
enum StaffRole: string
{
    case Admin = 'admin';
    case Moderator = 'moderator';
    case Curator = 'curator';

    /**
     * The permissions a starting role is created with.
     *
     * @return list<Permission>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::Moderator => [Permission::ReviewListings],
            self::Curator => [Permission::CurateStore],
        };
    }
}
