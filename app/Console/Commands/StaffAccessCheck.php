<?php

namespace App\Console\Commands;

use App\Enums\Permission;
use App\Enums\StaffRole;
use App\Models\Admin;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\PermissionRegistrar;

#[Signature('app:staff-access {email : Email of a staff account (admins table)} {--fix : Create missing permissions, give the account the admin role and clear the permission cache} {--super : With --fix, make the account a super admin (every menu, also future ones) instead}')]
#[Description('Explain which admin panel menus a staff account sees, and why some are missing')]
class StaffAccessCheck extends Command
{
    /**
     * The admin panel menu each permission opens.
     *
     * @var array<string, string>
     */
    private const MENUS = [
        'listings.review' => 'اپ‌ها (صف بررسی)',
        'comments.moderate' => 'نظرها',
        'users.manage' => 'کاربران سایت',
        'store.curate' => 'تب‌های فروشگاه',
        'staff.manage' => 'کارکنان و نقش‌ها',
        'statistics.view' => 'آمار دانلود',
        'settings.manage' => 'تنظیمات',
        'finance.manage' => 'فروش و کارمزد',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        foreach (['comments', 'notifications'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->warn("Table \"{$table}\" is missing: run php artisan migrate --force");
            }
        }

        $admin = Admin::where('email', mb_strtolower(trim($this->argument('email'))))->first();
        if (! $admin) {
            $this->error('No staff account with that email in the admins table. Website accounts (users table) cannot sign in to the panel; create one with php artisan app:create-admin.');

            return self::FAILURE;
        }

        if ($this->option('fix')) {
            $role = $this->option('super') ? StaffRole::SuperAdmin : StaffRole::Admin;
            $this->call('db:seed', ['--class' => RoleSeeder::class, '--force' => true]);
            $admin->assignRole($role->value);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $admin->refresh();
            $this->info("{$admin->email} has the {$role->value} role, and every permission exists.");
        }

        $missingPermissions = array_values(array_diff(
            Permission::values(),
            PermissionModel::where('guard_name', Admin::GUARD)->pluck('name')->all(),
        ));
        $roles = $admin->getRoleNames()->all();
        $granted = $admin->grantedPermissions();

        $this->line("Account: {$admin->name} <{$admin->email}>");
        $this->line('Roles: '.($roles ? implode(', ', $roles) : '(none)').($admin->isSuperAdmin() ? ' (super admin: every menu, also future ones)' : ''));
        $this->table(['Permission', 'Admin panel menu', 'Has it'], array_map(fn (string $permission): array => [
            $permission,
            self::MENUS[$permission] ?? '',
            in_array($permission, $granted, true) ? 'yes' : 'NO',
        ], Permission::values()));

        if ($missingPermissions) {
            $this->warn('Permissions not created yet: '.implode(', ', $missingPermissions).'. Run php artisan db:seed --class=RoleSeeder --force (or this command with --fix).');
        }
        if (! $roles) {
            $this->warn('The account has no staff role, so it sees no menus. Give it one in the panel, or run this command with --fix to make it an admin.');
        }
        if (count($granted) < count(Permission::values()) && ! $this->option('fix')) {
            $this->line('Run again with --fix to give this account every permission (the admin role).');
        }

        $this->line('The panel must reach this API: API_URL in the admin app\'s .env should be '.rtrim((string) config('app.url'), '/').'/api/v1');

        return self::SUCCESS;
    }
}
