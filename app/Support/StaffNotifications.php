<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\Admin;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifier;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Notifications in the admin panel: sent to every staff member who can act on the item, and marked
 * read for all of them once one of them has approved, rejected or deleted it.
 */
class StaffNotifications
{
    /**
     * Notify every staff member who holds the permission (directly or through a role).
     */
    public static function send(Permission $permission, Notification $notification): void
    {
        try {
            $staff = Admin::permission($permission->value)->get();
        } catch (PermissionDoesNotExist) {
            // the permission is created by RoleSeeder; before it runs nobody can act on the item
            return;
        }

        Notifier::send($staff, $notification);
    }

    /**
     * The item was handled: its unread notifications no longer need anyone's attention.
     *
     * @param  'comment_id'|'listing_id'  $key
     */
    public static function resolve(string $key, int $id): void
    {
        DatabaseNotification::query()
            ->where('notifiable_type', (new Admin)->getMorphClass())
            ->whereNull('read_at')
            ->where("data->{$key}", $id)
            ->update(['read_at' => now()]);
    }

    /**
     * The item is gone: remove its notifications, whose links would lead nowhere.
     *
     * @param  'comment_id'|'listing_id'  $key
     * @param  list<int>  $ids
     */
    public static function forget(string $key, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        DatabaseNotification::query()
            ->where('notifiable_type', (new Admin)->getMorphClass())
            ->whereIn("data->{$key}", $ids)
            ->delete();
    }
}
