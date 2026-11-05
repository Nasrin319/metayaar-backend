<?php

namespace App\Http\Controllers\Api\V1\Developer;

use App\Enums\ListingInviteStatus;
use App\Enums\ListingStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Developer\PrivateAccessRequest;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Models\User;
use App\Notifications\ListingAwaitingReview;
use App\Notifications\ListingInvitesAwaitingReview;
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

        $forReview = DB::transaction(function () use ($listing, $isPrivate, $request): array {
            $listing->forceFill(['is_private' => $isPrivate])->save();

            return $this->syncEmails($listing, $request->validated('emails'));
        });

        // while the app is public the list opens nothing, so staff only hear about it once it matters
        $forReview = match (true) {
            ! $isPrivate => [],
            $changed => $listing->invites()->where('status', ListingInviteStatus::Pending)->pluck('email')->all(),
            default => $forReview,
        };
        if ($forReview !== []) {
            StaffNotifications::send(Permission::ReviewListings, new ListingInvitesAwaitingReview($listing, $forReview));
        }

        // it was approved for a few people, so reaching the whole store needs its own review
        if ($changed && ! $isPrivate && $listing->status === ListingStatus::Published) {
            $listing->submitForReview($listing->submission_answers ?? []);
            StaffNotifications::send(Permission::ReviewListings, new ListingAwaitingReview($listing));
        }

        return (new ListingResource($listing->load('invites')))->additional(['pending_review' => $forReview]);
    }

    /**
     * Make the list match. An email with an account is active; one without waits for staff, and a decision
     * staff already made on it is kept, so saving the form again doesn't undo it.
     *
     * @param  list<string>  $emails
     * @return list<string> The emails added now that staff have to review.
     */
    private function syncEmails(Listing $listing, array $emails): array
    {
        $existing = $listing->invites()->pluck('status', 'email');
        $registered = User::whereIn('email', $emails)->pluck('email')->flip();
        $forReview = [];

        $listing->invites()->whereNotIn('email', $emails)->delete();

        foreach ($emails as $email) {
            $status = $registered->has($email) ? ListingInviteStatus::Active : $existing->get($email);
            if (! $status) {
                $status = ListingInviteStatus::Pending;
                $forReview[] = $email;
            }

            $listing->invites()->updateOrCreate(['email' => $email], ['status' => $status]);
        }

        return $forReview;
    }
}
