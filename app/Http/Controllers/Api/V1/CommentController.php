<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ListingStatus;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\Listing;
use App\Models\User;
use App\Notifications\CommentAwaitingReview;
use App\Support\StaffNotifications;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Comments on an app's store page and the developer's replies. Everything posted waits for staff
 * approval (see Admin\CommentReviewController) before other people see it.
 */
class CommentController extends Controller
{
    /**
     * Approved comments of a published app, newest first, each with its approved reply. A signed-in
     * viewer also sees their own comments still waiting or rejected, and the app's developer sees their
     * own replies in any status, so both know what happened to what they posted.
     */
    public function index(Request $request, Listing $listing): AnonymousResourceCollection
    {
        abort_unless($listing->status === ListingStatus::Published, 404);

        $viewer = $request->user('sanctum');
        $viewer = $viewer instanceof User ? $viewer : null;
        $isDeveloper = $viewer?->id === $listing->user_id;

        $comments = $listing->comments()
            ->whereNull('parent_id')
            ->where(fn (Builder $query) => $query->approved()
                ->when($viewer, fn (Builder $query) => $query->orWhere('user_id', $viewer->id)))
            ->with([
                'user',
                'replies' => fn (HasMany $query) => $query->with('user')
                    ->unless($isDeveloper, fn (Builder $query) => $query->approved())
                    ->oldest(),
            ])
            ->latest()
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return CommentResource::collection($comments)->additional([
            'meta' => [
                'approved_total' => $listing->comments()->whereNull('parent_id')->approved()->count(),
            ],
        ]);
    }

    /**
     * Post a comment on an app. It is saved as pending and shown once staff approve it.
     */
    public function store(StoreCommentRequest $request, Listing $listing): JsonResponse
    {
        Gate::authorize('create', [Comment::class, $listing]);

        $comment = new Comment(['body' => $request->validated('body')]);
        $comment->listing()->associate($listing);
        $comment->user()->associate($request->user());
        $comment->save();
        StaffNotifications::send(Permission::ModerateComments, new CommentAwaitingReview($comment));

        return (new CommentResource($comment->load('user')))
            ->additional(['message' => 'نظر شما ثبت شد و پس از تأیید مدیر نمایش داده می‌شود.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * The app's developer replies to an approved comment. The reply also waits for staff approval.
     */
    public function reply(StoreCommentRequest $request, Comment $comment): JsonResponse
    {
        Gate::authorize('reply', $comment);

        $reply = new Comment(['body' => $request->validated('body')]);
        $reply->listing()->associate($comment->listing_id);
        $reply->user()->associate($request->user());
        $reply->parent()->associate($comment);
        $reply->save();
        StaffNotifications::send(Permission::ModerateComments, new CommentAwaitingReview($reply));

        return (new CommentResource($reply->load('user')))
            ->additional(['message' => 'پاسخ شما ثبت شد و پس از تأیید مدیر نمایش داده می‌شود.'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Delete your own comment (with its reply) or your own reply.
     */
    public function destroy(Comment $comment): JsonResponse
    {
        Gate::authorize('delete', $comment);

        StaffNotifications::forget('comment_id', [$comment->id, ...$comment->replies()->pluck('id')]);
        $comment->delete();

        return response()->json(['message' => $comment->isReply() ? 'پاسخ حذف شد.' : 'نظر حذف شد.']);
    }
}
