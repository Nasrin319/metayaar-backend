<?php

namespace App\Models;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * A one-time code sent by email or SMS. Only its hash is stored. See App\Support\Otp.
 */
#[Fillable(['channel', 'destination', 'purpose', 'code', 'attempts', 'expires_at'])]
#[Hidden(['code'])]
class OtpCode extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => OtpChannel::class,
            'purpose' => OtpPurpose::class,
            'code' => 'hashed',
            'expires_at' => 'datetime',
        ];
    }
}
