<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Otp\SendOtpRequest;
use App\Models\User;
use App\Support\Otp;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Sends one-time codes to guests, for signing up, signing in, or resetting a password.
 */
class OtpController extends Controller
{
    /**
     * Send a code. Signup codes only go to addresses and numbers without an account, and login codes
     * only to ones with an account; both say so when that's not the case. Password reset codes only
     * go to accounts too, but answer the same either way.
     */
    public function send(SendOtpRequest $request, Otp $otp): JsonResponse
    {
        $purpose = $request->purpose();
        $account = $request->account();

        if ($purpose === OtpPurpose::Signup && $account) {
            throw ValidationException::withMessages([
                'destination' => [$request->channel() === OtpChannel::Email
                    ? 'حسابی با این ایمیل وجود دارد.'
                    : 'حسابی با این شماره موبایل وجود دارد.'],
            ]);
        }

        if ($purpose === OtpPurpose::Login && ! $account) {
            throw ValidationException::withMessages([
                'destination' => [$request->channel() === OtpChannel::Email
                    ? 'حسابی با این ایمیل پیدا نشد. ابتدا ثبت‌نام کنید.'
                    : 'این شماره موبایل برای هیچ حسابی ثبت نشده است. با ایمیل وارد شوید و شماره را در صفحه حساب اضافه کنید، یا ثبت‌نام کنید.'],
            ]);
        }

        if ($purpose === OtpPurpose::Login && $account->isDisabled()) {
            throw ValidationException::withMessages(['destination' => [User::DISABLED_MESSAGE]]);
        }

        if ($purpose === OtpPurpose::Signup || $account) {
            $otp->send($request->channel(), $request->destination(), $purpose);
        }

        return response()->json([
            'message' => 'If the destination can receive it, a code has been sent.',
            'expires_in' => Otp::TTL_MINUTES * 60,
            'resend_in' => Otp::RESEND_SECONDS,
        ]);
    }
}
