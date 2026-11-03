<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The signed-in staff member's notifications: new apps to review, new comments and replies to moderate.
 */
class NotificationController extends Controller
{
    /**
     * Newest first, with how many are unread for the badge in the panel's menu.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['unread' => ['nullable', 'boolean']]);
        $admin = $request->user();

        $notifications = $admin->notifications()
            ->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))
            ->paginate(20)
            ->withQueryString()
            ->through(fn (DatabaseNotification $notification) => [
                'id' => $notification->id,
                ...$notification->data,
                'read_at' => $notification->read_at,
                'created_at' => $notification->created_at,
            ]);

        return response()->json([
            ...$notifications->toArray(),
            'unread_count' => $admin->unreadNotifications()->count(),
        ]);
    }

    /**
     * Only the unread count, for the badge to refresh cheaply.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread_count' => $request->user()->unreadNotifications()->count()]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $request->user()->notifications()->findOrFail($notification)->markAsRead();

        return response()->json(['unread_count' => $request->user()->unreadNotifications()->count()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['unread_count' => 0]);
    }
}
