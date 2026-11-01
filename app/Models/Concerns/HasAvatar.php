<?php

namespace App\Models\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A profile picture in the "avatar_path" column, stored on the public disk under avatarDirectory().
 * Used by website users and staff accounts.
 */
trait HasAvatar
{
    /**
     * Where this account's avatar files go, unique per account.
     */
    abstract protected function avatarDirectory(): string;

    protected static function bootHasAvatar(): void
    {
        static::deleting(function (self $model): void {
            Storage::disk('public')->deleteDirectory($model->avatarDirectory());
        });
    }

    /**
     * Store a new avatar (or remove it with null) and delete the previous file.
     */
    public function replaceAvatar(?UploadedFile $file): void
    {
        $previousPath = $this->avatar_path;

        $this->forceFill(['avatar_path' => $file?->store($this->avatarDirectory(), 'public')])->save();

        if ($previousPath) {
            Storage::disk('public')->delete($previousPath);
        }
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null;
    }
}
