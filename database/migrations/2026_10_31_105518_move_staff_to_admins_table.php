<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Staff move from "users" to their own "admins" table. Every user holding a staff role gets an admin
 * account with the same name, email and password and the same role under the "admin" guard; the staff
 * roles and permissions of the "web" guard are removed, and so are their staff tokens. Listings
 * reviewed by staff now point at the admin account.
 *
 * Names are spelled out here rather than read from the enums, which keep changing after this runs.
 */
return new class extends Migration
{
    private const array STAFF_ROLES = [
        'admin' => ['listings.review', 'users.manage', 'store.curate', 'staff.manage'],
        'moderator' => ['listings.review'],
        'curator' => ['store.curate'],
    ];

    private const string USER = 'App\\Models\\User';

    private const string ADMIN = 'App\\Models\\Admin';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();

            // staff permissions and roles under the "admin" guard
            $permissionIds = [];
            foreach (array_unique(array_merge(...array_values(self::STAFF_ROLES))) as $name) {
                $permissionIds[$name] = $this->findOrCreate('permissions', $name, 'admin', $now);
            }
            $adminRoleIds = [];
            foreach (self::STAFF_ROLES as $role => $permissions) {
                $adminRoleIds[$role] = $this->findOrCreate('roles', $role, 'admin', $now);
                foreach ($permissions as $permission) {
                    DB::table('role_has_permissions')->insertOrIgnore([
                        'permission_id' => $permissionIds[$permission],
                        'role_id' => $adminRoleIds[$role],
                    ]);
                }
            }

            // copy every user holding a staff role
            $webRoleIds = DB::table('roles')->where('guard_name', 'web')->whereIn('name', array_keys(self::STAFF_ROLES))->pluck('id', 'name');
            $staff = DB::table('model_has_roles')
                ->join('users', 'users.id', '=', 'model_has_roles.model_id')
                ->where('model_type', self::USER)
                ->whereIn('role_id', $webRoleIds->values())
                ->get(['users.id', 'users.name', 'users.email', 'users.password', 'role_id']);

            $adminIdByUserId = [];
            foreach ($staff->groupBy('id') as $userId => $grants) {
                $user = $grants->first();
                $adminId = DB::table('admins')->where('email', $user->email)->value('id') ?? DB::table('admins')->insertGetId([
                    'name' => $user->name,
                    'email' => $user->email,
                    'password' => $user->password,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $adminIdByUserId[$userId] = $adminId;

                foreach ($grants as $grant) {
                    DB::table('model_has_roles')->insertOrIgnore([
                        'role_id' => $adminRoleIds[$webRoleIds->search($grant->role_id)],
                        'model_type' => self::ADMIN,
                        'model_id' => $adminId,
                    ]);
                }
            }

            // reviews now belong to admin accounts
            Schema::table('listings', fn (Blueprint $table) => $table->dropForeign(['reviewed_by']));
            $this->remapReviewers($adminIdByUserId);
            Schema::table('listings', fn (Blueprint $table) => $table->foreign('reviewed_by')->references('id')->on('admins')->nullOnDelete());

            // website accounts no longer carry staff roles or staff tokens
            DB::table('personal_access_tokens')
                ->where('tokenable_type', self::USER)
                ->where(function ($query): void {
                    foreach (array_keys(self::STAFF_ROLES) as $role) {
                        $query->orWhere('abilities', 'like', "%role:{$role}%");
                    }
                })
                ->delete();
            DB::table('model_has_roles')->where('model_type', self::USER)->whereIn('role_id', $webRoleIds->values())->delete();
            DB::table('model_has_permissions')->where('model_type', self::USER)->delete();
            DB::table('roles')->whereIn('id', $webRoleIds->values())->delete();
            DB::table('permissions')->where('guard_name', 'web')->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations: reviews point back at the website account with the same email, and the
     * staff roles return to those accounts under the "web" guard.
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $userIdByAdminId = DB::table('admins')->join('users', 'users.email', '=', 'admins.email')->pluck('users.id', 'admins.id');

            Schema::table('listings', fn (Blueprint $table) => $table->dropForeign(['reviewed_by']));
            $this->remapReviewers($userIdByAdminId->all());
            Schema::table('listings', fn (Blueprint $table) => $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete());

            $grants = DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_type', self::ADMIN)
                ->whereIn('roles.name', array_keys(self::STAFF_ROLES))
                ->get(['model_id', 'roles.name']);
            foreach ($grants as $grant) {
                if ($userId = $userIdByAdminId[$grant->model_id] ?? null) {
                    DB::table('model_has_roles')->insertOrIgnore([
                        'role_id' => $this->findOrCreate('roles', $grant->name, 'web', $now),
                        'model_type' => self::USER,
                        'model_id' => $userId,
                    ]);
                }
            }

            DB::table('model_has_roles')->where('model_type', self::ADMIN)->delete();
            DB::table('model_has_permissions')->where('model_type', self::ADMIN)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', self::ADMIN)->delete();
            DB::table('roles')->where('guard_name', 'admin')->delete();
            DB::table('permissions')->where('guard_name', 'admin')->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Point each reviewed listing at its reviewer's account in the other table, or at nobody.
     *
     * @param  array<int, int>  $newIds  old reviewer id => new reviewer id
     */
    private function remapReviewers(array $newIds): void
    {
        foreach (DB::table('listings')->whereNotNull('reviewed_by')->pluck('reviewed_by', 'id') as $listingId => $reviewerId) {
            DB::table('listings')->where('id', $listingId)->update(['reviewed_by' => $newIds[$reviewerId] ?? null]);
        }
    }

    private function findOrCreate(string $table, string $name, string $guard, mixed $now): int
    {
        return DB::table($table)->where(['name' => $name, 'guard_name' => $guard])->value('id')
            ?? DB::table($table)->insertGetId(['name' => $name, 'guard_name' => $guard, 'created_at' => $now, 'updated_at' => $now]);
    }
};
