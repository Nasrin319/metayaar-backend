<?php

namespace App\Enums;

/**
 * The staff roles every installation starts with. Admins can create more in the panel. Two are fixed
 * and can't be edited or deleted, so the panel can't lock itself out: "admin" holds every permission
 * (RoleSeeder gives it each new one), and "super-admin" holds none but passes every permission check
 * (AppServiceProvider), including ones added later. Only a super admin can give or take that role.
 */
enum StaffRole: string
{
    case SuperAdmin = 'super-admin';
    case Admin = 'admin';
    case Moderator = 'moderator';
    case Curator = 'curator';

    /**
     * Roles staff can't rename, edit or delete in the panel.
     */
    public function isLocked(): bool
    {
        return in_array($this, [self::SuperAdmin, self::Admin], true);
    }

    /**
     * The permissions a starting role is created with.
     *
     * @return list<Permission>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::SuperAdmin => [],
            self::Admin => Permission::cases(),
            self::Moderator => [Permission::ReviewListings, Permission::ModerateComments],
            self::Curator => [Permission::CurateStore, Permission::ManageContent],
        };
    }
}
