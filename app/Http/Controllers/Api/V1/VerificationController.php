<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Support\Mobile;
use App\Support\Otp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The signed-in user confirms their email, or adds (or changes) their mobile, with a one-time code.
 * A mobile is only saved on the account once its code is confirmed.
 */
class VerificationController extends Controller
{
    /**
     * Send a code to the account's email, or to the mobile number given in the request.
     */
    public function send(Request $request, OtpChannel $channel, Otp $otp): JsonResponse
    {
        $otp->send($channel, $this->destination($request, $channel), OtpPurpose::Verify, $channel->value);

        return response()->json([
            'message' => 'A code has been sent.',
            'expires_in' => Otp::TTL_MINUTES * 60,
            'resend_in' => Otp::RESEND_SECONDS,
        ]);
    }

    /**
     * Confirm the code: marks the email as verified, or saves the mobile as verified.
     */
    public function verify(Request $request, OtpChannel $channel, Otp $otp): UserResource
    {
        $request->validate(['code' => ['required', 'string', 'digits:6']]);
        $destination = $this->destination($request, $channel);

        $otp->verifyOrFail($channel, $destination, OtpPurpose::Verify, $request->string('code'));

        $user = $request->user();
        $user->forceFill($channel === OtpChannel::Email
            ? ['email_verified_at' => now()]
            : ['mobile' => $destination, 'mobile_verified_at' => now()])->save();

        return new UserResource($user->load('organization'));
    }

    /**
     * Remove the mobile from the account.
     */
    public function destroyMobile(Request $request): UserResource
    {
        $user = $request->user();
        $user->forceFill(['mobile' => null, 'mobile_verified_at' => null])->save();

        return new UserResource($user->load('organization'));
    }

    private function destination(Request $request, OtpChannel $channel): string
    {
        if ($channel === OtpChannel::Email) {
            return $request->user()->email;
        }

        $request->merge(['mobile' => Mobile::normalize($request->input('mobile'))]);
        $request->validate([
            'mobile' => [...$channel->destinationRules(), Rule::unique('users', 'mobile')->ignore($request->user()->id)],
        ], ['mobile.unique' => 'این شماره موبایل برای حساب دیگری ثبت شده است.']);

        return $request->input('mobile');
    }
}
