<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\Admin;
use App\Models\Setting;
use App\Models\User;
use App\Support\Sms\LogSmsSender;
use App\Support\Sms\MsgwaySmsSender;
use App\Support\Sms\SmsSender;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SmsSender::class, fn (): SmsSender => match (config('services.sms.driver')) {
            'msgway' => new MsgwaySmsSender(
                (string) config('services.msgway.api_key'),
                (int) config('services.msgway.otp_template_id'),
            ),
            default => new LogSmsSender,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimits();

        // a super admin passes every staff permission check, including permissions added later; other
        // abilities (policy rules such as "only listings in review can be approved") still apply
        Gate::before(fn ($user, string $ability): ?bool => $user instanceof Admin
            && in_array($ability, Permission::values(), true)
            && $user->isSuperAdmin() ? true : null);

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

    /**
     * The per-minute limits admins set in the panel (Setting): signing in, asking for one-time codes
     * and posting comments, counted per signed-in account or else per IP address. Routes use them as
     * throttle:login, throttle:otp and throttle:comments.
     */
    private function configureRateLimits(): void
    {
        $limit = fn (string $setting, string $message) => fn (Request $request): Limit => Limit::perMinute(Setting::integer($setting))
            ->by($request->user()?->getAuthIdentifier() ? get_class($request->user()).':'.$request->user()->getAuthIdentifier() : $request->ip())
            ->response(fn (Request $request, array $headers) => response()->json(['message' => $message], 429, $headers));

        RateLimiter::for('login', $limit('login_attempts_per_minute', 'تعداد تلاش‌های ورود زیاد است. یک دقیقه دیگر دوباره امتحان کنید.'));
        RateLimiter::for('otp', $limit('otp_requests_per_minute', 'تعداد درخواست کد زیاد است. یک دقیقه دیگر دوباره امتحان کنید.'));
        RateLimiter::for('comments', $limit('comments_per_minute', 'تعداد نظرهای ثبت‌شده زیاد است. یک دقیقه دیگر دوباره امتحان کنید.'));
    }
}
