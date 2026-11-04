<?php

namespace App\Models;

use App\Enums\PayoutRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A developer asking to be paid from their wallet. The wallet only changes once staff mark it paid;
 * see App\Support\Payouts.
 */
#[Fillable(['amount', 'holder_name', 'bank_name', 'sheba', 'card_number'])]
class PayoutRequest extends Model
{
    /**
     * @var array<string, string>
     */
    protected $attributes = ['status' => 'pending'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function developer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => PayoutRequestStatus::class,
            'sheba' => 'encrypted',
            'card_number' => 'encrypted',
            'reviewed_at' => 'datetime',
        ];
    }
}
