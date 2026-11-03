<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StaffRequest;
use App\Http\Resources\AdminResource;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff accounts of the admin panel.
 */
class StaffController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AdminResource::collection(Admin::with('roles.permissions', 'permissions')->orderBy('name')->get());
    }

    public function store(StaffRequest $request): AdminResource
    {
        $admin = DB::transaction(function () use ($request): Admin {
            $admin = Admin::create($request->safe()->only(['name', 'email', 'password']));
            $admin->syncRoles($request->validated('roles'));

            return $admin;
        });

        return new AdminResource($admin);
    }

    /**
     * Change a staff member's details and roles; a new password signs them out everywhere. Admins
     * cannot take the admin role away from themselves, so the panel always keeps one.
     */
    public function update(StaffRequest $request, Admin $admin): AdminResource
    {
        $roles = $request->collect('roles');

        if ($admin->is($request->user()) && ! $roles->contains(StaffRole::Admin->value)) {
            throw ValidationException::withMessages(['roles' => ['نمی‌توانید نقش مدیر را از حساب خودتان بردارید.']]);
        }

        DB::transaction(function () use ($request, $admin, $roles): void {
            $admin->fill($request->safe()->only(['name', 'email']));
            if ($request->filled('password')) {
                $admin->password = $request->validated('password');
                $admin->tokens()->when($admin->is($request->user()), fn ($query) => $query->whereKeyNot($request->user()->currentAccessToken()->getKey()))->delete();
            }
            $admin->save();
            $admin->syncRoles($roles->all());
        });

        return new AdminResource($admin->fresh());
    }

    public function destroy(Request $request, Admin $admin): Response
    {
        if ($admin->is($request->user())) {
            throw ValidationException::withMessages(['admin' => ['نمی‌توانید حساب خودتان را حذف کنید.']]);
        }

        DB::transaction(function () use ($admin): void {
            $admin->tokens()->delete();
            $admin->delete();
        });

        return response()->noContent();
    }
}
