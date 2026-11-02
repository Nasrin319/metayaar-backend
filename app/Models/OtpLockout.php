<?php

namespace App\Models;

use App\Enums\OtpChannel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * How many codes an email or mobile was sent without signing in, and until when it may get no more.
 * The limits are the otp_max_sends and otp_lockout_hours settings. See App\Support\Otp.
 */
#[Fillable(['channel', 'destination', 'sends', 'window_started_at', 'locked_until'])]
class OtpLockout extends Model
{
    public function isLocked(): bool
    {
        return (bool) $this->locked_until?->isFuture();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => OtpChannel::class,
            'sends' => 'integer',
            'window_started_at' => 'datetime',
            'locked_until' => 'datetime',
        ];
    }
}
