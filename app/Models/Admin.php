<?php

namespace App\Models;

use App\Models\Concerns\HasAvatar;
use Database\Factories\AdminFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
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
    use HasApiTokens, HasAvatar, HasFactory, HasRoles;

    public const string GUARD = 'admin';

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
