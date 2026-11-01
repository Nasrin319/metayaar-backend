<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\DownloadSource;
use App\Http\Controllers\Controller;
use App\Http\Resources\DownloadResource;
use App\Models\Download;
use App\Models\Listing;
use App\Support\DownloadStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Download statistics and the full download log (who, which app, when, from where) for staff.
 */
class DownloadController extends Controller
{
    /**
     * Store-wide numbers and the most downloaded apps in the period.
     */
    public function summary(Request $request): JsonResponse
    {
        $days = $this->days($request);
        $since = now()->startOfDay()->subDays($days - 1);

        $top = Listing::query()
            ->withCount(['downloads as period_downloads' => fn ($query) => $query->where('created_at', '>=', $since)])
            ->withCount('downloads')
            ->whereHas('downloads', fn ($query) => $query->where('created_at', '>=', $since))
            ->orderByDesc('period_downloads')
            ->limit(10)
            ->get()
            ->map(fn (Listing $listing) => [
                'id' => $listing->id,
                'title' => $listing->title,
                'slug' => $listing->slug,
                'icon_url' => $listing->iconUrl(),
                'period_downloads' => $listing->period_downloads,
                'downloads_count' => $listing->downloads_count,
            ]);

        return response()->json(['data' => [
            'stats' => DownloadStats::for(null, $days),
            'top_listings' => $top,
        ]]);
    }

    /**
     * The download log, newest first, filtered by app, user or source.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'listing_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'source' => ['nullable', Rule::enum(DownloadSource::class)],
        ]);

        $downloads = Download::with(['user', 'listing'])
            ->when($request->input('listing_id'), fn ($query, $id) => $query->where('listing_id', $id))
            ->when($request->input('user_id'), fn ($query, $id) => $query->where('user_id', $id))
            ->when($request->input('source'), fn ($query, $source) => $query->where('source', $source))
            ->latest('id')
            ->paginate(50);

        return DownloadResource::collection($downloads)->response();
    }

    /**
     * One app's numbers and its download log.
     */
    public function listing(Request $request, Listing $listing): JsonResponse
    {
        $downloads = $listing->downloads()->with('user')->latest('id')->paginate(50);

        return DownloadResource::collection($downloads)
            ->additional([
                'listing' => ['id' => $listing->id, 'title' => $listing->title, 'slug' => $listing->slug],
                'stats' => DownloadStats::for($listing->id, $this->days($request)),
            ])
            ->response();
    }

    private function days(Request $request): int
    {
        $request->validate(['days' => ['nullable', 'integer', Rule::in(DownloadStats::PERIODS)]]);

        return (int) $request->input('days', 30);
    }
}
