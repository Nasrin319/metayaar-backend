<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Otp\ResetPasswordWithCodeRequest;
use App\Models\User;
use App\Support\Otp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * "Forgot password": email a reset link to the website, then set a new password with its token. Or
 * send a code by email or SMS (POST auth/otp, purpose reset_password) and set it with the code.
 */
class PasswordResetController extends Controller
{
    /**
     * Email a reset link. The response is the same whether or not the address has an account, so the
     * endpoint can't be used to find out who is registered.
     */
    public function sendLink(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'string', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages(['email' => [__($status)]])->status(429);
        }

        return response()->json(['message' => 'If the address has an account, a reset link has been sent.']);
    }

    /**
     * Set a new password with the token from the email. Every session of the account is signed out.
     */
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => [__($status)]]);
        }

        return response()->json(['message' => 'Password has been reset.']);
    }

    /**
     * Set a new password with a code sent to the account's email or mobile. Every session of the account
     * is signed out.
     */
    public function resetWithCode(ResetPasswordWithCodeRequest $request, Otp $otp): JsonResponse
    {
        $user = $request->account();

        if (! $user || ! $otp->verify($request->channel(), $request->destination(), OtpPurpose::ResetPassword, $request->validated('code'))) {
            throw ValidationException::withMessages(['code' => ['کد واردشده نادرست یا منقضی است.']]);
        }

        $user->forceFill(['password' => $request->validated('password')])->save();
        $user->tokens()->delete();

        return response()->json(['message' => 'Password has been reset.']);
    }
}
