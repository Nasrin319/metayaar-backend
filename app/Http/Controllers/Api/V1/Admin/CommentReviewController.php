<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CommentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Rules\PlainText;
use App\Support\StaffNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The comment moderation queue: staff approve or reject every comment and developer reply before the
 * store shows it.
 */
class CommentReviewController extends Controller
{
    /**
     * Comments and replies in a status (pending by default), oldest first so the queue is worked in
     * order; approved and rejected ones newest first. Each comes with its app and, for a reply, the
     * comment it answers.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(CommentStatus::class)],
            'listing_id' => ['nullable', 'integer'],
        ]);

        $status = $request->enum('status', CommentStatus::class) ?? CommentStatus::Pending;

        $comments = Comment::with(['user', 'listing:id,title,slug,user_id', 'parent.user:id,name'])
            ->where('status', $status)
            ->when($request->integer('listing_id'), fn ($query, $listingId) => $query->where('listing_id', $listingId))
            ->orderBy('created_at', $status === CommentStatus::Pending ? 'asc' : 'desc')
            ->orderBy('id', $status === CommentStatus::Pending ? 'asc' : 'desc')
            ->paginate(25)
            ->withQueryString();

        return CommentResource::collection($comments)->additional([
            'meta' => ['pending_total' => Comment::where('status', CommentStatus::Pending)->count()],
        ]);
    }

    public function approve(Request $request, Comment $comment): CommentResource
    {
        $comment->approve($request->user());
        StaffNotifications::resolve('comment_id', $comment->id);

        return new CommentResource($comment->load(['user', 'listing:id,title,slug,user_id', 'parent.user:id,name']));
    }

    /**
     * Reject a comment or reply; the reason is optional and only staff see it.
     */
    public function reject(Request $request, Comment $comment): CommentResource
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:500', new PlainText]]);

        $comment->reject($request->user(), $request->input('reason'));
        StaffNotifications::resolve('comment_id', $comment->id);

        return new CommentResource($comment->load(['user', 'listing:id,title,slug,user_id', 'parent.user:id,name']));
    }

    /**
     * Delete a comment for good, with its replies.
     */
    public function destroy(Comment $comment): JsonResponse
    {
        StaffNotifications::forget('comment_id', [$comment->id, ...$comment->replies()->pluck('id')]);
        $comment->delete();

        return response()->json(['message' => 'نظر حذف شد.']);
    }
}
