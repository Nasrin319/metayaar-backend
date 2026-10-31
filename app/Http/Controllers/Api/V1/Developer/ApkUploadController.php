<?php

namespace App\Http\Controllers\Api\V1\Developer;

use App\Enums\ListingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Developer\ApkChunkRequest;
use App\Http\Requests\Api\V1\Developer\StartApkUploadRequest;
use App\Http\Resources\ListingResource;
use App\Models\ApkUpload;
use App\Models\Listing;
use App\Support\ApkIcon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Resumable chunked APK uploads: start an upload, append chunks in order, then complete it.
 * Files live on the private "apks" disk and are never reachable through a public URL.
 */
class ApkUploadController extends Controller
{
    /**
     * Start an upload. Any unfinished upload for the listing is discarded. A listing of any type can
     * take a file; it becomes an APK listing once the upload completes.
     */
    public function store(StartApkUploadRequest $request, Listing $listing): JsonResponse
    {
        Gate::authorize('update', $listing);

        $listing->apkUploads->each->delete();

        $apkUpload = $listing->apkUploads()->create([
            'user_id' => $request->user()->id,
            'original_name' => basename($request->string('filename')),
            'size' => $request->integer('size'),
        ]);
        Storage::disk('apks')->put($apkUpload->partialPath(), '');

        return response()->json(['data' => $this->state($apkUpload)], 201);
    }

    /**
     * How many bytes have arrived, so an interrupted upload can resume.
     */
    public function show(Listing $listing, ApkUpload $apkUpload): JsonResponse
    {
        Gate::authorize('update', $listing);

        return response()->json(['data' => $this->state($apkUpload)]);
    }

    /**
     * Append one chunk. Chunks must arrive in order: the offset must equal the bytes received so far.
     */
    public function chunk(ApkChunkRequest $request, Listing $listing, ApkUpload $apkUpload): JsonResponse
    {
        Gate::authorize('update', $listing);

        $lock = Cache::lock("apk-upload:{$apkUpload->id}", 60);
        if (! $lock->get()) {
            return response()->json(['message' => 'Another chunk is being written.', 'data' => $this->state($apkUpload)], 409);
        }

        try {
            $apkUpload->refresh();
            $chunk = $request->file('chunk');

            if ($request->integer('offset') !== $apkUpload->received_bytes) {
                return response()->json(['message' => 'Chunk offset does not match the bytes received.', 'data' => $this->state($apkUpload)], 409);
            }

            if ($apkUpload->received_bytes + $chunk->getSize() > $apkUpload->size) {
                throw ValidationException::withMessages(['chunk' => ['The chunk goes past the declared file size.']]);
            }

            $target = fopen(Storage::disk('apks')->path($apkUpload->partialPath()), 'ab');
            $source = fopen($chunk->getRealPath(), 'rb');
            stream_copy_to_stream($source, $target);
            fclose($source);
            fclose($target);

            $apkUpload->increment('received_bytes', $chunk->getSize());
        } finally {
            $lock->release();
        }

        return response()->json(['data' => $this->state($apkUpload)]);
    }

    /**
     * Verify the finished file and attach it to the listing, replacing any previous APK.
     * Replacing the APK of a published listing sends it back to review.
     */
    public function complete(Listing $listing, ApkUpload $apkUpload): ListingResource
    {
        Gate::authorize('update', $listing);

        if (! $apkUpload->isComplete()) {
            throw ValidationException::withMessages(['chunk' => ['The upload is not complete yet.']]);
        }

        $disk = Storage::disk('apks');
        $partialPath = $disk->path($apkUpload->partialPath());

        if (filesize($partialPath) !== $apkUpload->size || ! $this->isZipArchive($partialPath)) {
            $apkUpload->delete();

            throw ValidationException::withMessages(['chunk' => ['The uploaded file is not a valid APK.']]);
        }

        $path = "listings/{$listing->id}/".Str::uuid().'.apk';
        $sha256 = hash_file('sha256', $partialPath);
        $disk->move($apkUpload->partialPath(), $path);

        $listing->attachApk($path, $apkUpload->original_name, $apkUpload->size, $sha256);
        $apkUpload->delete();

        try {
            $icon = ApkIcon::extract($disk->path($path));
            if ($icon) {
                $listing->attachApkIcon($icon['contents'], $icon['extension']);
            }
        } catch (Throwable $e) {
            // An unreadable manifest only costs the automatic icon, not the upload.
            report($e);
        }

        // A reviewer approved the previous file, so a replacement must be reviewed again.
        if ($listing->status === ListingStatus::Published) {
            $listing->submitForReview();
        }

        return new ListingResource($listing);
    }

    /**
     * Cancel an upload and discard what arrived so far.
     */
    public function destroy(Listing $listing, ApkUpload $apkUpload): JsonResponse
    {
        Gate::authorize('update', $listing);

        $apkUpload->delete();

        return response()->json(null, 204);
    }

    /**
     * APKs are ZIP archives, which start with the "PK\x03\x04" signature.
     */
    private function isZipArchive(string $path): bool
    {
        $handle = fopen($path, 'rb');
        $signature = fread($handle, 4);
        fclose($handle);

        return $signature === "PK\x03\x04";
    }

    /**
     * @return array{id: string, original_name: string, size: int, received_bytes: int, chunk_size: int}
     */
    private function state(ApkUpload $apkUpload): array
    {
        return [
            'id' => $apkUpload->id,
            'original_name' => $apkUpload->original_name,
            'size' => $apkUpload->size,
            'received_bytes' => $apkUpload->received_bytes,
            'chunk_size' => ApkUpload::chunkSize(),
        ];
    }
}
