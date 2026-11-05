<?php

namespace App\Notifications;

use App\Models\Listing;
use Illuminate\Notifications\Notification;

/**
 * A developer put emails without an account on a private listing's list, and staff decide on them.
 */
class ListingInvitesAwaitingReview extends Notification
{
    /**
     * @param  list<string>  $emails
     */
    public function __construct(public Listing $listing, public array $emails) {}

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
     * What the admin panel shows. `invite_listing_id` is apart from `listing_id`, so reviewing the app
     * itself doesn't mark these emails as handled.
     *
     * @return array{kind: string, invite_listing_id: int, title: string, body: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'listing',
            'invite_listing_id' => $this->listing->id,
            'title' => "ایمیل‌های انتشار خصوصی «{$this->listing->title}» منتظر بررسی است",
            'body' => implode('، ', $this->emails),
            'url' => "/listings/{$this->listing->id}",
        ];
    }
}
