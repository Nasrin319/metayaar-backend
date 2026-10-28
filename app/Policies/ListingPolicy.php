<?php

namespace App\Policies;

use App\Enums\ListingStatus;
use App\Enums\Permission;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ListingPolicy
{
    /**
     * Determine whether the user can view the listing in the developer portal.
     */
    public function view(User $user, Listing $listing): bool
    {
        return $listing->user_id === $user->id;
    }

    /**
     * Determine whether the user can edit the listing. Listings waiting in review are locked.
     */
    public function update(User $user, Listing $listing): bool
    {
        return $listing->user_id === $user->id && $listing->status !== ListingStatus::InReview;
    }

    /**
     * Determine whether the user can submit the listing for review.
     */
    public function submit(User $user, Listing $listing): bool
    {
        return $listing->user_id === $user->id && $listing->status->canBeSubmitted();
    }

    /**
     * Determine whether the user can delete the listing.
     */
    public function delete(User $user, Listing $listing): bool
    {
        return $listing->user_id === $user->id;
    }

    /**
     * Determine whether the user can download the listing's APK. Developers and reviewers can always
     * fetch it; players need a published listing that is free or that they have purchased.
     */
    public function download(User $user, Listing $listing): Response
    {
        if (! $listing->hasApk()) {
            return Response::denyAsNotFound();
        }

        if ($listing->user_id === $user->id || $user->hasPermissionTo(Permission::ReviewListings->value)) {
            return Response::allow();
        }

        if ($listing->status !== ListingStatus::Published) {
            return Response::denyAsNotFound();
        }

        if (! $listing->isFree() && ! $user->hasPurchased($listing)) {
            return Response::denyWithStatus(402, 'Purchase this app to download it.');
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can approve or reject the listing.
     */
    public function review(User $user, Listing $listing): Response
    {
        if (! $user->hasPermissionTo(Permission::ReviewListings->value)) {
            return Response::deny();
        }

        return $listing->status->canBeReviewed()
            ? Response::allow()
            : Response::denyWithStatus(409, 'Only listings waiting in review can be approved or rejected.');
    }
}
