<?php

namespace App\Models\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * One picture in the "image_path" column, stored on the public disk in a folder named after the table.
 */
trait HasImage
{
    protected static function bootHasImage(): void
    {
        static::deleted(function (self $model): void {
            if ($model->image_path) {
                Storage::disk('public')->delete($model->image_path);
            }
        });
    }

    /**
     * Store a new image (or remove it with null) and delete the previous file.
     */
    public function replaceImage(?UploadedFile $file): void
    {
        $previousPath = $this->image_path;

        $this->forceFill(['image_path' => $file?->store($this->getTable(), 'public')])->save();

        if ($previousPath) {
            Storage::disk('public')->delete($previousPath);
        }
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
