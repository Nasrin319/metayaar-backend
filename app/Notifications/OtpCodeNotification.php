<?php

namespace App\Notifications;

use App\Enums\OtpPurpose;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a one-time code.
 */
class OtpCodeNotification extends Notification
{
    public function __construct(
        public string $code,
        public OtpPurpose $purpose,
        public int $minutes,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("کد {$this->purpose->label()} متایار: {$this->code}")
            ->greeting('سلام،')
            ->line("کد {$this->purpose->label()} شما در متایار:")
            ->line("**{$this->code}**")
            ->line("این کد تا {$this->minutes} دقیقه معتبر است. آن را در اختیار هیچ‌کس قرار ندهید.")
            ->line('اگر شما این درخواست را نداده‌اید، این ایمیل را نادیده بگیرید.')
            ->salutation('تیم متایار');
    }
}
