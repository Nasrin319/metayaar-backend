<?php

namespace App\Http\Controllers\Api\V1\Developer;

use App\Http\Controllers\Controller;
use App\Http\Resources\DownloadResource;
use App\Models\Listing;
use App\Support\DownloadStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Download statistics of the developer's own listing, with who downloaded it (names only).
 */
class ListingDownloadController extends Controller
{
    public function __invoke(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('update', $listing);
        $request->validate(['days' => ['nullable', 'integer', Rule::in(DownloadStats::PERIODS)]]);

        $downloads = $listing->downloads()->with('user')->latest('id')->paginate(20);

        return DownloadResource::collection($downloads)
            ->additional(['stats' => DownloadStats::for($listing->id, (int) $request->input('days', 30))])
            ->response();
    }
}
