<?php

namespace App\Models;

use Database\Factories\PayoutAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a developer's sales are paid out: the bank account (Sheba) and the identity of its holder.
 * Required before a paid listing can be sent to review.
 */
#[Fillable(['holder_name', 'national_id', 'bank_name', 'sheba', 'card_number', 'postal_code', 'address'])]
#[Hidden(['user_id'])]
class PayoutAccount extends Model
{
    /** @use HasFactory<PayoutAccountFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'national_id' => 'encrypted',
            'sheba' => 'encrypted',
            'card_number' => 'encrypted',
        ];
    }
}
