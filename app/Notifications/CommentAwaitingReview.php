<?php

namespace App\Notifications;

use App\Models\Comment;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Tells staff who moderate comments that a comment or a developer reply is waiting for approval.
 */
class CommentAwaitingReview extends Notification
{
    public function __construct(public Comment $comment) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * What the admin panel shows. `comment_id` lets the notification be resolved once the comment is moderated.
     *
     * @return array{kind: string, comment_id: int, listing_id: int, title: string, body: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        $listing = $this->comment->listing;
        $author = $this->comment->user->name;

        return [
            'kind' => $this->comment->isReply() ? 'reply' : 'comment',
            'comment_id' => $this->comment->id,
            'listing_id' => $listing->id,
            'title' => $this->comment->isReply()
                ? "پاسخ جدید توسعه‌دهنده برای «{$listing->title}»"
                : "نظر جدید برای «{$listing->title}»",
            'body' => "{$author}: ".Str::limit($this->comment->body, 120),
            'url' => '/comments',
        ];
    }
}
