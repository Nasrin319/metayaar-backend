<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DownloadSource;
use App\Http\Controllers\Controller;
use App\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadController extends Controller
{
    /**
     * Stream a listing's APK to a signed-in user who is allowed to have it, and log the download.
     *
     * The website proxies these requests, so it passes the visitor's IP and user agent in
     * X-Client-IP / X-Client-User-Agent; they are only used for the log.
     */
    public function __invoke(Request $request, Listing $listing): StreamedResponse
    {
        Gate::authorize('download', $listing);

        $userAgent = $request->header('X-Client-User-Agent') ?? $request->userAgent();
        $ip = $request->header('X-Client-IP');

        $listing->downloads()->create([
            'user_id' => $request->user()->id,
            'source' => DownloadSource::fromUserAgent($userAgent),
            'version_name' => $listing->version_name,
            'ip_address' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : $request->ip(),
            'user_agent' => $userAgent ? Str::limit($userAgent, 500, '') : null,
        ]);

        return Storage::disk('apks')->download($listing->apk_path, "{$listing->slug}.apk", [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
