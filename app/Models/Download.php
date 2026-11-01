<?php

namespace App\Models;

use App\Enums\DownloadSource;
use Database\Factories\DownloadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One APK download, logged by DownloadController. See App\Support\DownloadStats for the numbers.
 */
#[Fillable(['listing_id', 'user_id', 'source', 'version_name', 'ip_address', 'user_agent', 'created_at'])]
class Download extends Model
{
    /** @use HasFactory<DownloadFactory> */
    use HasFactory;

    /** A log: rows are never updated. */
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

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
            'source' => DownloadSource::class,
            'created_at' => 'datetime',
        ];
    }
}
