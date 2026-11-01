<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Sms\LogSmsSender;
use App\Support\Sms\MelipayamakSmsSender;
use App\Support\Sms\SmsSender;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SmsSender::class, fn (): SmsSender => match (config('services.sms.driver')) {
            'melipayamak' => new MelipayamakSmsSender(
                (string) config('services.melipayamak.username'),
                (string) config('services.melipayamak.password'),
                (int) config('services.melipayamak.otp_body_id'),
            ),
            default => new LogSmsSender,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Password reset emails link to the website, which sends the token back to the API.
        $resetUrl = fn (User $user, string $token): string => config('app.frontend_url').'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]);

        ResetPassword::toMailUsing(fn (User $user, string $token): MailMessage => (new MailMessage)
            ->subject('بازیابی رمز عبور متایار')
            ->greeting("سلام {$user->name}،")
            ->line('درخواست بازیابی رمز عبور حساب شما در متایار دریافت شد.')
            ->action('تعیین رمز عبور جدید', $resetUrl($user, $token))
            ->line('این لینک تا '.config('auth.passwords.users.expire').' دقیقه معتبر است.')
            ->line('اگر شما این درخواست را نداده‌اید، این ایمیل را نادیده بگیرید؛ رمز عبور شما تغییر نمی‌کند.')
            ->salutation('تیم متایار'));
    }
}
