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
     * every permission, so newly added permissions reach it.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Role::cases() as $role) {
            RoleModel::findOrCreate($role->value, 'web');
        }

        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value, Admin::GUARD);
        }

        // DatabaseSeeder runs without model events, so creating a permission does not refresh the cache itself.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (StaffRole::cases() as $staffRole) {
            $role = RoleModel::where(['name' => $staffRole->value, 'guard_name' => Admin::GUARD])->first();
            $isNew = ! $role;
            $role ??= RoleModel::create(['name' => $staffRole->value, 'guard_name' => Admin::GUARD]);

            if ($isNew || $staffRole === StaffRole::Admin) {
                $role->syncPermissions(array_map(fn (Permission $permission) => $permission->value, $staffRole->defaultPermissions()));
            }
        }
    }
}
