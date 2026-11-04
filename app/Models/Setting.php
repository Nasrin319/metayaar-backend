<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A value admins change in the panel (Admin\SettingController). Keys not saved yet use DEFAULTS.
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /**
     * Every setting and its value until an admin changes it.
     *
     * @var array<string, int>
     */
    public const array DEFAULTS = [
        // codes sent to one email or mobile without signing in before it is locked out
        'otp_max_sends' => 5,
        // how long the lockout lasts
        'otp_lockout_hours' => 5,
        // how long a code works, how soon another may be sent, and how many wrong tries it allows
        'otp_ttl_minutes' => 5,
        'otp_resend_seconds' => 60,
        'otp_max_attempts' => 5,
        // requests per minute from one visitor (signed-in user, else IP) before 429; see AppServiceProvider
        'login_attempts_per_minute' => 10,
        'otp_requests_per_minute' => 5,
        'comments_per_minute' => 5,
        // how long a sign-in lasts, on the website and in the admin panel
        'session_hours' => 12,
        // Metayaar's percent of each sale, for listings without their own commission_percent
        'default_commission_percent' => 20,
        // days after a purchase before a developer can ask to be paid for that sale
        'payout_hold_days' => 5,
    ];

    private const string CACHE_KEY = 'settings';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * Every setting with its current value.
     *
     * @return array<string, mixed>
     */
    public static function values(): array
    {
        // only the saved values are cached; defaults are added on every read, so a setting added in
        // a new release shows up without clearing the cache
        $saved = Cache::rememberForever(self::CACHE_KEY, fn (): array => self::query()->pluck('value', 'key')->all());

        return [...self::DEFAULTS, ...array_intersect_key($saved, self::DEFAULTS)];
    }

    public static function integer(string $key): int
    {
        return (int) (self::values()[$key] ?? self::DEFAULTS[$key]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function put(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
