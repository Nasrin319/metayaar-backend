<?php

namespace App\Support;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\OtpLockout;
use App\Models\Setting;
use App\Notifications\OtpCodeNotification;
use App\Support\Sms\SmsSender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sends and checks one-time codes. A destination has at most one live code per purpose; sending a new
 * one replaces it. Codes expire, allow a few wrong guesses, and are used up once accepted.
 *
 * An email or mobile that is sent otp_max_sends codes without one being accepted gets no more codes
 * for otp_lockout_hours. How long a code works (otp_ttl_minutes), how soon another may be sent
 * (otp_resend_seconds) and how many wrong tries it allows (otp_max_attempts) are admin settings too.
 * Accepting a code clears the count.
 */
class Otp
{
    public const int LENGTH = 6;

    /** Minutes a new code works for. */
    public static function ttlMinutes(): int
    {
        return Setting::integer('otp_ttl_minutes');
    }

    /** Seconds before a new code can be sent to the same destination for the same purpose. */
    public static function resendSeconds(): int
    {
        return Setting::integer('otp_resend_seconds');
    }

    /** Wrong guesses a code allows before it stops working. */
    public static function maxAttempts(): int
    {
        return Setting::integer('otp_max_attempts');
    }

    public function __construct(private SmsSender $sms) {}

    /**
     * Send a new code. Throws a 429 validation error, keyed by $errorKey, when one was just sent or
     * the destination is locked out, and a 503 one when the SMS panel or mail server fails.
     */
    public function send(OtpChannel $channel, string $destination, OtpPurpose $purpose, string $errorKey = 'destination'): void
    {
        $lockout = $this->lockout($channel, $destination);

        if ($lockout->isLocked()) {
            throw ValidationException::withMessages([
                $errorKey => [self::lockedMessage($lockout)],
            ])->status(429);
        }

        $latest = $this->latest($channel, $destination, $purpose);

        if ($latest && $latest->created_at->diffInSeconds(now()) < self::resendSeconds()) {
            $wait = (int) ceil(self::resendSeconds() - $latest->created_at->diffInSeconds(now()));
            throw ValidationException::withMessages([
                $errorKey => ["کد به‌تازگی ارسال شده است. {$wait} ثانیه دیگر دوباره امتحان کنید."],
            ])->status(429);
        }

        $code = str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);

        $this->query($channel, $destination, $purpose)->delete();
        $otp = OtpCode::create([
            'channel' => $channel,
            'destination' => $destination,
            'purpose' => $purpose,
            'code' => $code,
            'expires_at' => now()->addMinutes(self::ttlMinutes()),
        ]);

        try {
            match ($channel) {
                OtpChannel::Email => Notification::route('mail', $destination)
                    ->notify(new OtpCodeNotification($code, $purpose, self::ttlMinutes())),
                OtpChannel::Mobile => $this->sms->sendOtp($destination, $code),
            };
        } catch (Throwable $exception) {
            // the SMS panel or mail server refused or didn't answer: nothing reached the person, so
            // drop the code (a retry isn't held back by the resend wait) and say so instead of "sent"
            report($exception);
            $otp->delete();

            throw ValidationException::withMessages([
                $errorKey => [$channel === OtpChannel::Mobile
                    ? 'ارسال پیامک انجام نشد. چند دقیقه دیگر دوباره تلاش کنید یا با ایمیل وارد شوید.'
                    : 'ارسال ایمیل انجام نشد. چند دقیقه دیگر دوباره تلاش کنید.'],
            ])->status(503);
        }

        $this->countSend($lockout);
    }

    /**
     * "ارسال کد تا ۴ ساعت و ۲۰ دقیقه دیگر ممکن نیست." for a locked-out destination.
     */
    public static function lockedMessage(OtpLockout $lockout): string
    {
        $minutes = max(1, (int) ceil(now()->diffInMinutes($lockout->locked_until)));
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        $wait = trim(($hours ? "{$hours} ساعت" : '').($hours && $rest ? ' و ' : '').($rest ? "{$rest} دقیقه" : ''));

        return "به دلیل درخواست بیش از حد کد بدون ورود موفق، ارسال کد تا {$wait} دیگر ممکن نیست.";
    }

    /**
     * The send counter of a destination, started over once its window or lockout has passed.
     */
    private function lockout(OtpChannel $channel, string $destination): OtpLockout
    {
        $lockout = OtpLockout::firstOrNew(['channel' => $channel, 'destination' => $destination]);
        $hours = Setting::integer('otp_lockout_hours');

        $lockoutEnded = $lockout->locked_until && ! $lockout->isLocked();
        $windowEnded = ! $lockout->window_started_at || $lockout->window_started_at->addHours($hours)->isPast();
        if ($lockoutEnded || $windowEnded) {
            $lockout->fill(['sends' => 0, 'window_started_at' => now(), 'locked_until' => null]);
        }

        return $lockout;
    }

    private function countSend(OtpLockout $lockout): void
    {
        $lockout->sends++;
        if ($lockout->sends >= Setting::integer('otp_max_sends')) {
            $lockout->locked_until = now()->addHours(Setting::integer('otp_lockout_hours'));
        }
        $lockout->save();
    }

    /**
     * Whether the code is the live one for this destination and purpose. A wrong guess counts as an
     * attempt. With $consume, an accepted code is used up; pass false to check several codes before
     * using any of them, then call forget().
     */
    public function verify(OtpChannel $channel, string $destination, OtpPurpose $purpose, string $code, bool $consume = true): bool
    {
        $otp = $this->latest($channel, $destination, $purpose);

        if (! $otp || $otp->expires_at->isPast() || $otp->attempts >= self::maxAttempts()) {
            return false;
        }

        if (! Hash::check($code, $otp->code)) {
            $otp->increment('attempts');

            return false;
        }

        if ($consume) {
            $otp->delete();
        }

        // signing in with a code clears the count of codes sent
        OtpLockout::query()->where('channel', $channel)->where('destination', $destination)->delete();

        return true;
    }

    /**
     * verify(), throwing a validation error keyed by $errorKey when the code is wrong.
     */
    public function verifyOrFail(OtpChannel $channel, string $destination, OtpPurpose $purpose, string $code, string $errorKey = 'code', bool $consume = true): void
    {
        if (! $this->verify($channel, $destination, $purpose, $code, $consume)) {
            throw ValidationException::withMessages([
                $errorKey => ['کد واردشده نادرست یا منقضی است.'],
            ]);
        }
    }

    /**
     * Use up the live code, after it was accepted with verify(consume: false).
     */
    public function forget(OtpChannel $channel, string $destination, OtpPurpose $purpose): void
    {
        $this->query($channel, $destination, $purpose)->delete();
    }

    private function latest(OtpChannel $channel, string $destination, OtpPurpose $purpose): ?OtpCode
    {
        return $this->query($channel, $destination, $purpose)->latest('id')->first();
    }

    /**
     * @return Builder<OtpCode>
     */
    private function query(OtpChannel $channel, string $destination, OtpPurpose $purpose)
    {
        return OtpCode::query()
            ->where('channel', $channel)
            ->where('destination', $destination)
            ->where('purpose', $purpose);
    }
}
