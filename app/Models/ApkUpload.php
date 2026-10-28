<?php

namespace App\Models;

use Database\Factories\ApkUploadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * An APK being uploaded in chunks. Chunks are appended to a partial file on the private
 * "apks" disk; once every byte has arrived the file is verified and attached to the listing.
 */
#[Fillable(['user_id', 'original_name', 'size'])]
class ApkUpload extends Model
{
    /** @use HasFactory<ApkUploadFactory> */
    use HasFactory, HasUuids, Prunable;

    /**
     * Largest chunk the client may send in one request (5 MB), below PHP's upload limit.
     */
    public const int CHUNK_SIZE = 5 * 1024 * 1024;

    /**
     * Largest APK accepted (4 GB).
     */
    public const int MAX_SIZE = 4 * 1024 * 1024 * 1024;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'received_bytes' => 0,
    ];

    protected static function booted(): void
    {
        static::deleted(function (ApkUpload $upload): void {
            Storage::disk('apks')->delete($upload->partialPath());
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'received_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * Path of the partial file on the "apks" disk.
     */
    public function partialPath(): string
    {
        return "uploads/{$this->id}.part";
    }

    public function isComplete(): bool
    {
        return $this->received_bytes === $this->size;
    }

    /**
     * Uploads abandoned for a day are removed together with their partial file.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::where('updated_at', '<=', now()->minus(days: 1));
    }
}
