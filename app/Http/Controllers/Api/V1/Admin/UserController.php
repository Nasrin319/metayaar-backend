<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateUserRolesRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    /**
     * Users, newest first, optionally searched by name or email.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        $users = User::with('roles')
            ->when($request->input('search'), fn ($query, $search) => $query->where(
                fn ($query) => $query->whereLike('name', "%{$search}%")->orWhereLike('email', "%{$search}%")
            ))
            ->latest()
            ->paginate(25);

        return UserResource::collection($users);
    }

    /**
     * Replace the user's website roles (player, developer).
     */
    public function updateRoles(UpdateUserRolesRequest $request, User $user): UserResource
    {
        $user->syncRoles($request->collect('roles')->all());

        return new UserResource($user->fresh());
    }
}
