<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to sign in to the admin panel with a solved captcha, kept for 90 days.
 */
#[Fillable(['admin_id', 'email', 'ip_address', 'user_agent', 'succeeded', 'created_at'])]
class AdminLogin extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    private const KEEP_DAYS = 90;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['succeeded' => 'boolean'];
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<=', now()->minus(days: self::KEEP_DAYS));
    }
}
