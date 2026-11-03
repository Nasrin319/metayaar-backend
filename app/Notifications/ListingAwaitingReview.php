<?php

namespace App\Notifications;

use App\Models\Listing;
use Illuminate\Notifications\Notification;

/**
 * Tells staff who review apps that a developer submitted an app (new, or changed after a rejection).
 */
class ListingAwaitingReview extends Notification
{
    public function __construct(public Listing $listing, public bool $resubmitted = false) {}

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
     * What the admin panel shows. `listing_id` lets the notification be resolved once the app is reviewed.
     *
     * @return array{kind: string, listing_id: int, title: string, body: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'listing',
            'listing_id' => $this->listing->id,
            'title' => $this->resubmitted
                ? "«{$this->listing->title}» دوباره برای بررسی ارسال شد"
                : "اپ جدید برای بررسی: «{$this->listing->title}»",
            'body' => "توسعه‌دهنده: {$this->listing->developer->name}",
            'url' => "/listings/{$this->listing->id}",
        ];
    }
}
