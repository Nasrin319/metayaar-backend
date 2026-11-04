<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateUserRolesRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\StaffNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Website accounts (members and developers) as staff manage them: roles, disabling and deleting.
 */
class UserController extends Controller
{
    /**
     * Users, newest first, optionally searched by name or email, or only active or disabled ones.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'disabled'])],
        ]);

        $users = User::with('roles')
            ->withCount(['listings', 'purchases'])
            ->when($request->input('search'), fn ($query, $search) => $query->where(
                fn ($query) => $query->whereLike('name', "%{$search}%")->orWhereLike('email', "%{$search}%")
            ))
            ->when($request->input('status') === 'disabled', fn ($query) => $query->whereNotNull('disabled_at'))
            ->when($request->input('status') === 'active', fn ($query) => $query->whereNull('disabled_at'))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return UserResource::collection($users);
    }

    /**
     * Replace the user's website roles (member, developer).
     */
    public function updateRoles(UpdateUserRolesRequest $request, User $user): UserResource
    {
        $user->syncRoles($request->collect('roles')->all());

        return new UserResource($user->fresh());
    }

    /**
     * Disable the account: it is signed out everywhere and cannot sign in again until enabled.
     */
    public function disable(User $user): UserResource
    {
        $user->disable();

        return new UserResource($user->loadCount(['listings', 'purchases']));
    }

    public function enable(User $user): UserResource
    {
        $user->enable();

        return new UserResource($user->loadCount(['listings', 'purchases']));
    }

    /**
     * Delete the account for good, with its comments. Accounts with apps or purchases are kept, since
     * deleting them would also erase sales and the developers' income; disable those instead.
     */
    public function destroy(User $user): JsonResponse
    {
        if ($user->listings()->exists() || $user->purchases()->exists()) {
            abort(409, 'این کاربر اپ یا خرید ثبت‌شده دارد و با حذف آن سوابق فروش هم پاک می‌شود. به‌جای حذف، حساب را غیرفعال کنید.');
        }

        StaffNotifications::forget('comment_id', $user->comments()->pluck('id')->all());
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'حساب کاربر حذف شد.']);
    }
}
