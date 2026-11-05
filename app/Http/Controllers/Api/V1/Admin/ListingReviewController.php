<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ListingInviteStatus;
use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RejectListingRequest;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Models\ListingInvite;
use App\Support\StaffNotifications;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The moderation queue: reviewers inspect submitted listings (and their APKs) and approve or reject them.
 */
class ListingReviewController extends Controller
{
    /**
     * Listings in a status, oldest submission first so the queue is worked in order.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(ListingStatus::class)],
            'type' => ['nullable', Rule::enum(ListingType::class)],
        ]);

        $listings = Listing::with('developer')
            ->withCount('downloads')
            ->where('status', $request->input('status', ListingStatus::InReview->value))
            ->when($request->input('type'), fn ($query, $type) => $query->where('type', $type))
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->paginate(25);

        return ListingResource::collection($listings);
    }

    public function show(Listing $listing): ListingResource
    {
        return new ListingResource($listing->load(['developer.payoutAccount', 'invites'])->loadCount('invites'));
    }

    /**
     * Let an email without an account onto the private listing's list: once its owner signs up and
     * confirms it, they can open the listing.
     */
    public function approveInvite(Listing $listing, ListingInvite $invite): ListingResource
    {
        return $this->decideInvite($listing, $invite, ListingInviteStatus::Active);
    }

    public function rejectInvite(Listing $listing, ListingInvite $invite): ListingResource
    {
        return $this->decideInvite($listing, $invite, ListingInviteStatus::Rejected);
    }

    private function decideInvite(Listing $listing, ListingInvite $invite, ListingInviteStatus $status): ListingResource
    {
        abort_unless($invite->status === ListingInviteStatus::Pending, 409, 'برای این ایمیل قبلاً تصمیم گرفته شده است.');

        $invite->update(['status' => $status]);

        if (! $listing->invites()->where('status', ListingInviteStatus::Pending)->exists()) {
            StaffNotifications::resolve('invite_listing_id', $listing->id);
        }

        return new ListingResource($listing->load(['developer', 'invites'])->loadCount('invites'));
    }

    public function approve(Request $request, Listing $listing): ListingResource
    {
        Gate::authorize('review', $listing);

        $listing->approve($request->user());
        StaffNotifications::resolve('listing_id', $listing->id);

        return new ListingResource($listing->load('developer'));
    }

    public function reject(RejectListingRequest $request, Listing $listing): ListingResource
    {
        Gate::authorize('review', $listing);

        $listing->reject($request->user(), $request->string('reason'));
        StaffNotifications::resolve('listing_id', $listing->id);

        return new ListingResource($listing->load('developer'));
    }

    /**
     * Stream the listing's APK to a reviewer, whatever its status.
     */
    public function download(Listing $listing): StreamedResponse
    {
        Gate::authorize('reviewDownload', $listing);

        return Storage::disk('apks')->download($listing->apk_path, $listing->downloadFileName(), [
            'Content-Type' => $listing->downloadContentType(),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
