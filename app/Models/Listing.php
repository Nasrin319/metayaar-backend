<?php

namespace App\Models;

use App\Enums\Comfort;
use App\Enums\ListingInviteStatus;
use App\Enums\ListingMedia;
use App\Enums\ListingStatus;
use App\Enums\ListingType;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Fillable([
    'title', 'type', 'tagline', 'description', 'category', 'price', 'package_name', 'external_url',
    'version_code', 'version_name', 'seated', 'comfort', 'supported_devices', 'tags', 'links', 'trailer_url',
    'early_access',
])]
#[Hidden(['apk_path', 'icon_path', 'title_image_path', 'cover_path', 'screenshot_paths'])]
class Listing extends Model
{
    /** @use HasFactory<ListingFactory> */
    use HasFactory;

    public const MAX_SCREENSHOTS = 8;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'price' => 0,
        'comfort' => 'comfortable',
    ];

    protected static function booted(): void
    {
        static::creating(function (Listing $listing): void {
            $listing->slug ??= static::uniqueSlug($listing->title);
        });

        static::deleting(function (Listing $listing): void {
            $listing->apkUploads->each->delete();

            if ($listing->apk_path) {
                Storage::disk('apks')->delete($listing->apk_path);
            }

            Storage::disk('public')->deleteDirectory("listings/{$listing->id}");
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
            'type' => ListingType::class,
            'status' => ListingStatus::class,
            'price' => 'integer',
            'commission_percent' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'apk_size' => 'integer',
            'apk_uploaded_at' => 'datetime',
            'screenshot_paths' => 'array',
            'icon_from_apk' => 'boolean',
            'version_code' => 'integer',
            'seated' => 'boolean',
            'comfort' => Comfort::class,
            'supported_devices' => 'array',
            'tags' => 'array',
            'links' => 'array',
            'early_access' => 'boolean',
            'submission_answers' => 'array',
            'file_report' => 'array',
            'is_private' => 'boolean',
        ];
    }

    /**
     * The developer who owns the listing.
     *
     * @return BelongsTo<User, $this>
     */
    public function developer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The staff member who approved or rejected the listing.
     *
     * @return BelongsTo<Admin, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }

    /**
     * Every download of this listing's APK.
     *
     * @return HasMany<Download, $this>
     */
    public function downloads(): HasMany
    {
        return $this->hasMany(Download::class);
    }

    /**
     * Chunked APK uploads in progress for this listing.
     *
     * @return HasMany<ApkUpload, $this>
     */
    public function apkUploads(): HasMany
    {
        return $this->hasMany(ApkUpload::class);
    }

    /**
     * Metayaar's percent of each sale: the one an admin set for this listing, else the default setting.
     */
    public function commissionPercent(): int
    {
        return $this->commission_percent ?? Setting::integer('default_commission_percent');
    }

    /**
     * Ownership records of members who bought this listing.
     *
     * @return HasMany<Purchase, $this>
     */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /**
     * The email addresses allowed to see this listing while it is private.
     *
     * @return HasMany<ListingInvite, $this>
     */
    public function invites(): HasMany
    {
        return $this->hasMany(ListingInvite::class);
    }

    /**
     * Whether the store shows this listing to the person: a published public listing to anyone, a
     * published private one to its developer and to invited addresses. An address only counts once its
     * owner has verified it, or anyone could type someone else's email into their profile to get in.
     */
    public function isAvailableTo(?User $user): bool
    {
        if ($this->status !== ListingStatus::Published) {
            return false;
        }
        if (! $this->is_private) {
            return true;
        }
        if (! $user) {
            return false;
        }

        return $this->user_id === $user->id
            || ($user->email_verified_at && $this->invites()->where('status', ListingInviteStatus::Active)->where('email', mb_strtolower($user->email))->exists());
    }

    public function hasApk(): bool
    {
        return $this->apk_path !== null;
    }

    /**
     * The name a download of the listing's file is saved under, keeping its real extension.
     */
    public function downloadFileName(): string
    {
        return $this->slug.'.'.pathinfo((string) $this->apk_path, PATHINFO_EXTENSION);
    }

    /**
     * The desktop app only installs responses of the APK type, so Windows files never reach a headset.
     */
    public function downloadContentType(): string
    {
        return match (pathinfo((string) $this->apk_path, PATHINFO_EXTENSION)) {
            'exe' => 'application/vnd.microsoft.portable-executable',
            'zip' => 'application/zip',
            default => 'application/vnd.android.package-archive',
        };
    }

    public function isFree(): bool
    {
        return $this->price === 0;
    }

    /**
     * Replace the listing's file with a verified one already stored on the "apks" disk. The listing is
     * then distributed as that file instead of any external link. A Windows listing stays one; any other
     * becomes an APK listing.
     *
     * @param  array<string, mixed>|null  $report  What FileChecks found in the file.
     */
    public function attachApk(string $path, string $originalName, int $size, string $sha256, ?array $report = null): void
    {
        $previousPath = $this->apk_path;

        $this->forceFill([
            'type' => $this->type === ListingType::Windows ? ListingType::Windows : ListingType::Apk,
            'file_report' => $report,
            'external_url' => null,
            'apk_path' => $path,
            'apk_original_name' => $originalName,
            'apk_size' => $size,
            'apk_sha256' => $sha256,
            'apk_uploaded_at' => now(),
        ])->save();

        if ($previousPath && $previousPath !== $path) {
            Storage::disk('apks')->delete($previousPath);
        }
    }

    public function iconUrl(): ?string
    {
        return $this->icon_path ? Storage::disk('public')->url($this->icon_path) : null;
    }

    public function titleImageUrl(): ?string
    {
        return $this->title_image_path ? Storage::disk('public')->url($this->title_image_path) : null;
    }

    public function coverUrl(): ?string
    {
        return $this->cover_path ? Storage::disk('public')->url($this->cover_path) : null;
    }

    /**
     * @return list<string>
     */
    public function screenshotUrls(): array
    {
        return array_map(fn (string $path) => Storage::disk('public')->url($path), $this->screenshot_paths ?? []);
    }

    /**
     * Store an image on the public disk as the icon or cover (replacing the old one) or as the last screenshot.
     */
    public function attachMedia(ListingMedia $kind, UploadedFile $image): void
    {
        $path = $image->store("listings/{$this->id}", 'public');

        if ($kind === ListingMedia::Screenshot) {
            $this->forceFill(['screenshot_paths' => [...($this->screenshot_paths ?? []), $path]])->save();

            return;
        }

        $column = "{$kind->value}_path";
        $previousPath = $this->{$column};
        $this->forceFill([$column => $path, ...($kind === ListingMedia::Icon ? ['icon_from_apk' => false] : [])])->save();

        if ($previousPath) {
            Storage::disk('public')->delete($previousPath);
        }
    }

    /**
     * Use the launcher icon found in the listing's APK as its store icon. An icon the developer uploaded
     * themselves is kept; one taken from an earlier APK is replaced.
     */
    public function attachApkIcon(string $contents, string $extension): void
    {
        if ($this->icon_path && ! $this->icon_from_apk) {
            return;
        }

        $path = "listings/{$this->id}/".Str::random(40).".{$extension}";
        Storage::disk('public')->put($path, $contents);

        $previousPath = $this->icon_path;
        $this->forceFill(['icon_path' => $path, 'icon_from_apk' => true])->save();

        if ($previousPath) {
            Storage::disk('public')->delete($previousPath);
        }
    }

    /**
     * Delete the icon or cover, or the screenshot at the given position.
     */
    public function detachMedia(ListingMedia $kind, ?int $index = null): void
    {
        if ($kind === ListingMedia::Screenshot) {
            $paths = $this->screenshot_paths ?? [];
            $removed = $paths[$index] ?? null;
            unset($paths[$index]);
            $this->forceFill(['screenshot_paths' => array_values($paths)])->save();
        } else {
            $column = "{$kind->value}_path";
            $removed = $this->{$column};
            $this->forceFill([$column => null, ...($kind === ListingMedia::Icon ? ['icon_from_apk' => false] : [])])->save();
        }

        if ($removed) {
            Storage::disk('public')->delete($removed);
        }
    }

    /**
     * Send the listing to the moderation queue with the developer's answers to the submission checklist.
     *
     * @param  array<string, mixed>  $answers
     */
    public function submitForReview(array $answers = []): void
    {
        $this->forceFill([
            'status' => ListingStatus::InReview,
            'submitted_at' => now(),
            'submission_answers' => $answers,
            'rejection_reason' => null,
        ])->save();
    }

    /**
     * Members' comments and the developer's replies, in every status.
     *
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * Publish the listing in the store.
     */
    public function approve(Admin $reviewer): void
    {
        $this->forceFill([
            'status' => ListingStatus::Published,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ])->save();
    }

    /**
     * Send the listing back to the developer with the reason it was not accepted.
     */
    public function reject(Admin $reviewer, string $reason): void
    {
        $this->forceFill([
            'status' => ListingStatus::Rejected,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ])->save();
    }

    /**
     * Copy the listing's text and settings into a new draft under another package name. Images and the
     * APK stay with the original.
     */
    public function duplicate(?string $packageName): self
    {
        $copy = $this->replicate([
            'slug', 'status', 'apk_path', 'apk_original_name', 'apk_size', 'apk_sha256', 'apk_uploaded_at',
            'icon_path', 'icon_from_apk', 'title_image_path', 'cover_path', 'screenshot_paths', 'submitted_at',
            'submission_answers', 'reviewed_by', 'reviewed_at', 'rejection_reason', 'file_report',
        ]);
        $copy->forceFill(['title' => "{$this->title} (copy)", 'package_name' => $packageName, 'status' => ListingStatus::Draft]);
        $copy->save();

        return $copy;
    }

    private static function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'app';
        $slug = $base;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
