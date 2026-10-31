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
     * Preferred chunk size (5 MB). The actual size may be smaller, see chunkSize().
     */
    public const int CHUNK_SIZE = 5 * 1024 * 1024;

    /**
     * Room left in a request for the multipart headers and the "offset" field.
     */
    private const int REQUEST_OVERHEAD = 64 * 1024;

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

    /**
     * The largest chunk this server accepts in one request: CHUNK_SIZE, or less when PHP's
     * upload_max_filesize or post_max_size is lower. PHP discards larger files before Laravel sees them.
     */
    public static function chunkSize(): int
    {
        $limits = array_filter([
            self::iniBytes('upload_max_filesize'),
            self::iniBytes('post_max_size') - self::REQUEST_OVERHEAD,
        ], fn (int $bytes) => $bytes > 0);

        return max(256 * 1024, min([self::CHUNK_SIZE, ...$limits]));
    }

    /**
     * A php.ini size such as "2M" in bytes; 0 means unlimited.
     */
    private static function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }

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
