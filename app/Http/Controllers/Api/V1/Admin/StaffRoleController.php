<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\Permission;
use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StaffRoleRequest;
use App\Http\Resources\StaffRoleResource;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Staff roles: named sets of permissions that admins create and assign to staff accounts.
 */
class StaffRoleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        // counted by hand: Spatie resolves a role's "users" model from its guard, which a query has none of
        $roles = Role::where('guard_name', Admin::GUARD)
            ->select('roles.*')
            ->selectSub(
                DB::table('model_has_roles')->selectRaw('count(*)')->whereColumn('role_id', 'roles.id')->where('model_type', Admin::class),
                'users_count',
            )
            ->with('permissions')
            ->orderBy('id')
            ->get();

        return StaffRoleResource::collection($roles);
    }

    /**
     * Every permission a role can hold.
     */
    public function permissions(): JsonResponse
    {
        return response()->json(['data' => Permission::values()]);
    }

    public function store(StaffRoleRequest $request): StaffRoleResource
    {
        $role = Role::create(['name' => $request->validated('name'), 'guard_name' => Admin::GUARD]);
        $role->syncPermissions($request->validated('permissions'));

        return new StaffRoleResource($role->load('permissions')->loadCount('users'));
    }

    public function update(StaffRoleRequest $request, Role $role): StaffRoleResource
    {
        $this->ensureEditable($role);

        $role->update(['name' => $request->validated('name')]);
        $role->syncPermissions($request->validated('permissions'));

        return new StaffRoleResource($role->load('permissions')->loadCount('users'));
    }

    /**
     * Delete a role. Staff who held it simply lose its permissions.
     */
    public function destroy(Role $role): Response
    {
        $this->ensureEditable($role);

        $role->delete();

        return response()->noContent();
    }

    private function ensureEditable(Role $role): void
    {
        abort_unless($role->guard_name === Admin::GUARD, 404);

        if ($role->name === StaffRole::SuperAdmin->value) {
            throw ValidationException::withMessages(['name' => ['نقش مدیر ارشد همیشه به همه بخش‌ها دسترسی دارد و قابل تغییر نیست.']]);
        }
        if ($role->name === StaffRole::Admin->value) {
            throw ValidationException::withMessages(['name' => ['نقش مدیر همیشه همه دسترسی‌ها را دارد و قابل تغییر نیست.']]);
        }
    }
}
