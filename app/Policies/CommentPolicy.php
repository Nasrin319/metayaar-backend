<?php

namespace App\Policies;

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CommentPolicy
{
    /**
     * Determine whether the user can comment on the app. Only published apps take comments, and a
     * developer answers on their own app with replies instead.
     */
    public function create(User $user, Listing $listing): Response
    {
        if (! $listing->isAvailableTo($user)) {
            return Response::denyAsNotFound();
        }

        return $listing->user_id === $user->id
            ? Response::deny('برای اپ خودتان نمی‌توانید نظر ثبت کنید؛ به نظر کاربران پاسخ دهید.')
            : Response::allow();
    }

    /**
     * Determine whether the user can reply to the comment: only the app's developer, once per comment,
     * and only to an approved comment from a member.
     */
    public function reply(User $user, Comment $comment): Response
    {
        if ($comment->listing->user_id !== $user->id) {
            return Response::deny('فقط توسعه‌دهنده این اپ می‌تواند به نظرها پاسخ دهد.');
        }

        if ($comment->isReply() || $comment->status !== CommentStatus::Approved) {
            return Response::denyWithStatus(409, 'فقط به نظرهای تأییدشده می‌توان پاسخ داد.');
        }

        return $comment->hasActiveReply()
            ? Response::denyWithStatus(409, 'برای این نظر قبلاً پاسخ ثبت کرده‌اید.')
            : Response::allow();
    }

    /**
     * Determine whether the user can delete the comment or reply: only the person who wrote it.
     */
    public function delete(User $user, Comment $comment): bool
    {
        return $comment->user_id === $user->id;
    }
}
