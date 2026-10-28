<?php

namespace App\Http\Controllers\Api\V1\Developer;

use App\Enums\ListingMedia;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Developer\ListingMediaRequest;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The icon, cover and screenshots shown on a listing's store page.
 */
class ListingMediaController extends Controller
{
    /**
     * Replace the icon or cover, or add a screenshot.
     */
    public function store(ListingMediaRequest $request, Listing $listing, ListingMedia $kind): ListingResource
    {
        Gate::authorize('update', $listing);

        if ($kind === ListingMedia::Screenshot && count($listing->screenshot_paths ?? []) >= Listing::MAX_SCREENSHOTS) {
            throw ValidationException::withMessages([
                'image' => ['A listing can have at most '.Listing::MAX_SCREENSHOTS.' screenshots.'],
            ]);
        }

        $listing->attachMedia($kind, $request->file('image'));

        return new ListingResource($listing);
    }

    /**
     * Remove the icon or cover, or the screenshot at the given position.
     */
    public function destroy(Listing $listing, ListingMedia $kind, ?int $index = null): ListingResource
    {
        Gate::authorize('update', $listing);

        if ($kind === ListingMedia::Screenshot && ! isset(($listing->screenshot_paths ?? [])[$index])) {
            abort(404);
        }

        $listing->detachMedia($kind, $index);

        return new ListingResource($listing);
    }
}
