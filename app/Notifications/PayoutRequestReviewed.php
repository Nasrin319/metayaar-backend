<?php

namespace App\Notifications;

use App\Enums\PayoutRequestStatus;
use App\Models\PayoutRequest;
use Illuminate\Notifications\Notification;

/**
 * Tells a developer that their payout request was paid or turned down.
 */
class PayoutRequestReviewed extends Notification
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
     * What the developer's notifications page shows.
     *
     * @return array{kind: string, payout_request_id: int, title: string, body: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        $amount = number_format($this->request->amount).' تومان';
        $paid = $this->request->status === PayoutRequestStatus::Paid;
        $reference = $this->request->reference ? " کد پیگیری: {$this->request->reference}" : '';

        return [
            'kind' => $paid ? 'payout_paid' : 'payout_rejected',
            'payout_request_id' => $this->request->id,
            'title' => $paid ? 'درخواست تسویه شما پرداخت شد' : 'درخواست تسویه شما رد شد',
            'body' => $paid
                ? "مبلغ {$amount} به حساب شما واریز شد.{$reference}"
                : "درخواست {$amount} رد شد و این مبلغ در کیف پول شما باقی ماند. دلیل: {$this->request->rejection_reason}",
            'url' => '/developer/wallet#payout-requests',
        ];
    }
}
