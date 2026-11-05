<?php

namespace App\Http\Controllers\Api\V1\Developer;

use App\Enums\ListingStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Developer\PrivateAccessRequest;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Notifications\ListingAwaitingReview;
use App\Support\StaffNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Who a listing is published for: everyone, or only the email addresses its developer chose.
 */
class PrivateAccessController extends Controller
{
    public function update(PrivateAccessRequest $request, Listing $listing): ListingResource
    {
        Gate::authorize('view', $listing);

        $isPrivate = $request->boolean('is_private');
        $changed = $listing->is_private !== $isPrivate;

        if ($changed && $listing->status === ListingStatus::InReview) {
            throw ValidationException::withMessages(['is_private' => ['تا پایان بررسی نمی‌توان نوع انتشار را تغییر داد.']]);
        }

        DB::transaction(function () use ($listing, $isPrivate, $request): void {
            $listing->forceFill(['is_private' => $isPrivate])->save();
            $listing->invites()->delete();
            $listing->invites()->createMany(array_map(fn (string $email): array => ['email' => $email], $request->validated('emails')));
        });

        // it was approved for a few people, so reaching the whole store needs its own review
        if ($changed && ! $isPrivate && $listing->status === ListingStatus::Published) {
            $listing->submitForReview($listing->submission_answers ?? []);
            StaffNotifications::send(Permission::ReviewListings, new ListingAwaitingReview($listing));
        }

        return new ListingResource($listing->load('invites'));
    }
}
