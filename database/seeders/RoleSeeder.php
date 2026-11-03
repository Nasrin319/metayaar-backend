<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\StaffRole;
use App\Models\Admin;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Create the website roles, every staff permission and the starting staff roles. Staff roles that
     * already exist keep the permissions admins gave them in the panel; only "admin" is reset to
     * every permission. A permission created by this run (added in a new release) is also given to
     * the existing starting roles that include it by default.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Role::cases() as $role) {
            RoleModel::findOrCreate($role->value, 'web');
        }

        $addedPermissions = [];
        foreach (Permission::cases() as $permission) {
            if (PermissionModel::findOrCreate($permission->value, Admin::GUARD)->wasRecentlyCreated) {
                $addedPermissions[] = $permission;
            }
        }

        // DatabaseSeeder runs without model events, so creating a permission does not refresh the cache itself.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (StaffRole::cases() as $staffRole) {
            $role = RoleModel::where(['name' => $staffRole->value, 'guard_name' => Admin::GUARD])->first();
            $isNew = ! $role;
            $role ??= RoleModel::create(['name' => $staffRole->value, 'guard_name' => Admin::GUARD]);

            if ($isNew || $staffRole === StaffRole::Admin) {
                $role->syncPermissions(array_map(fn (Permission $permission) => $permission->value, $staffRole->defaultPermissions()));
            } else {
                // a permission added in a new release reaches the existing roles that start with it
                $role->givePermissionTo(array_map(
                    fn (Permission $permission) => $permission->value,
                    array_filter($addedPermissions, fn (Permission $permission) => in_array($permission, $staffRole->defaultPermissions(), true)),
                ));
            }
        }
    }
}
