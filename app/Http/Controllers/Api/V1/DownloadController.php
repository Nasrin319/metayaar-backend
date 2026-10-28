<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadController extends Controller
{
    /**
     * Stream a listing's APK to a signed-in user who is allowed to have it.
     */
    public function __invoke(Listing $listing): StreamedResponse
    {
        Gate::authorize('download', $listing);

        return Storage::disk('apks')->download($listing->apk_path, "{$listing->slug}.apk", [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
