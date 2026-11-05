<?php

namespace App\Enums;

/**
 * Admin panel abilities, held by staff roles (guard "admin"). Each one is checked somewhere in code,
 * so new permissions are added here as features need them; admins combine them into roles in the panel.
 */
enum Permission: string
{
    case ReviewListings = 'listings.review';
    case ModerateComments = 'comments.moderate';
    case ManageUsers = 'users.manage';
    case CurateStore = 'store.curate';
    case ManageContent = 'content.manage';
    case ManageStaff = 'staff.manage';
    case ViewStatistics = 'statistics.view';
    case ManageSettings = 'settings.manage';
    case ManageFinance = 'finance.manage';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
