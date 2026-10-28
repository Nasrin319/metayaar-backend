<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateUserRolesRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    /**
     * Users, newest first, optionally searched by name or email.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        $users = User::with('roles', 'permissions', 'roles.permissions')
            ->when($request->input('search'), fn ($query, $search) => $query->where(
                fn ($query) => $query->whereLike('name', "%{$search}%")->orWhereLike('email', "%{$search}%")
            ))
            ->latest()
            ->paginate(25);

        return UserResource::collection($users);
    }

    /**
     * Replace the user's roles. Admins cannot remove their own admin role, so the panel always keeps one.
     */
    public function updateRoles(UpdateUserRolesRequest $request, User $user): UserResource
    {
        $roles = $request->collect('roles');

        if ($user->is($request->user()) && ! $roles->contains(Role::Admin->value)) {
            throw ValidationException::withMessages(['roles' => ['You cannot remove your own admin role.']]);
        }

        $user->syncRoles($roles->all());

        return new UserResource($user->fresh());
    }
}
