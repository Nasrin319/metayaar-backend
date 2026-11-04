<?php

namespace App\Notifications;

use App\Models\PayoutRequest;
use Illuminate\Notifications\Notification;

/**
 * Tells staff who handle finance that a developer asked to be paid.
 */
class PayoutRequested extends Notification
{
    public function __construct(public PayoutRequest $request) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * What the admin panel shows. `payout_request_id` lets it be resolved once the request is handled.
     *
     * @return array{kind: string, payout_request_id: int, title: string, body: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'payout',
            'payout_request_id' => $this->request->id,
            'title' => "درخواست تسویه از {$this->request->developer->name}",
            'body' => 'مبلغ '.number_format($this->request->amount).' تومان',
            'url' => '/finance/payouts',
        ];
    }
}
