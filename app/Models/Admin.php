<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\StaffRole;
use App\Models\Concerns\HasAvatar;
use Database\Factories\AdminFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * A staff account of the admin panel, kept apart from website users: it signs in only through the
 * admin login and its roles and permissions live under the "admin" guard.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class Admin extends Authenticatable
{
    /** @use HasFactory<AdminFactory> */
    use HasApiTokens, HasAvatar, HasFactory, HasRoles, Notifiable;

    public const string GUARD = 'admin';

    /**
     * Passes every permission check without holding any (see AppServiceProvider).
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole(StaffRole::SuperAdmin->value);
    }

    /**
     * The permissions this staff member can use: all of them for a super admin, otherwise what their
     * roles and direct permissions give.
     *
     * @return list<string>
     */
    public function grantedPermissions(): array
    {
        $names = $this->isSuperAdmin() ? Permission::values() : $this->getAllPermissions()->pluck('name')->all();
        sort($names);

        return array_values(array_unique($names));
    }

    /**
     * The guard spatie/laravel-permission stores staff roles and permissions under.
     */
    protected string $guard_name = self::GUARD;

    protected function avatarDirectory(): string
    {
        return "avatars/admins/{$this->id}";
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }
}
