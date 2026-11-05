<?php

namespace App\Models;

use App\Enums\ListingInviteStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An email address a developer put on one of their private listings' list. It opens the listing once
 * active: straight away for an email with an account, after staff approve it for one without.
 */
#[Fillable(['email', 'status'])]
class ListingInvite extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => ListingInviteStatus::class];
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
