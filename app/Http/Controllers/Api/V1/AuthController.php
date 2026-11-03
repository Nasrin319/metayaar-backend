<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountType;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\Otp\LoginWithCodeRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Requests\Api\V1\UpdateOrganizationRequest;
use App\Http\Requests\Api\V1\UpdatePasswordRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\Setting;
use App\Models\User;
use App\Support\Otp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new account with the given self-registerable role (player or developer), as an
     * individual or, with account_type=organization, as an organization with its details.
     */
    public function register(RegisterRequest $request, Role $role, Otp $otp): JsonResponse
    {
        abort_unless($role->isSelfRegisterable(), 403, "Registration as {$role->value} is not allowed.");

        // check every code before using any up, so one wrong code doesn't make the user resend both
        $email = $request->validated('email');
        $mobile = $request->validated('mobile');
        $otp->verifyOrFail(OtpChannel::Email, $email, OtpPurpose::Signup, $request->validated('email_code'), 'email_code', consume: false);
        if ($mobile) {
            $otp->verifyOrFail(OtpChannel::Mobile, $mobile, OtpPurpose::Signup, $request->validated('mobile_code'), 'mobile_code', consume: false);
        }

        $user = DB::transaction(function () use ($request, $role, $mobile): User {
            $user = User::create([
                ...$request->safe()->only(['name', 'email', 'password']),
                'account_type' => $request->accountType(),
            ]);
            $user->forceFill([
                'email_verified_at' => now(),
                'mobile' => $mobile,
                'mobile_verified_at' => $mobile ? now() : null,
            ])->save();
            $user->assignRole($role->value);

            if ($user->isOrganization()) {
                $user->organization()->create([
                    'name' => $request->validated('organization_name'),
                    'national_id' => $request->validated('organization_national_id'),
                    'phone' => $request->validated('organization_phone'),
                ]);
            }

            return $user;
        });

        $otp->forget(OtpChannel::Email, $email, OtpPurpose::Signup);
        if ($mobile) {
            $otp->forget(OtpChannel::Mobile, $mobile, OtpPurpose::Signup);
        }

        return $this->tokenResponse($user, $role, $request->input('device_name'), 201);
    }

    /**
     * Log in to the panel of the given role. The account must hold that role.
     */
    public function login(LoginRequest $request, Role $role): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $this->ensureNotDisabled($user, 'email');
        $this->ensureHasRole($user, $role, 'email');

        return $this->tokenResponse($user, $role, $request->input('device_name'));
    }

    /**
     * Log in to the panel of the given role with a code sent by POST auth/otp (purpose login) to the
     * account's email or mobile, instead of the password.
     */
    public function loginWithCode(LoginWithCodeRequest $request, Role $role, Otp $otp): JsonResponse
    {
        $user = $request->account();

        if (! $user || ! $otp->verify($request->channel(), $request->destination(), OtpPurpose::Login, $request->validated('code'), consume: false)) {
            throw ValidationException::withMessages(['code' => ['کد واردشده نادرست یا منقضی است.']]);
        }

        $this->ensureNotDisabled($user, 'destination');
        $this->ensureHasRole($user, $role, 'destination');
        $otp->forget($request->channel(), $request->destination(), OtpPurpose::Login);

        // receiving the code proves the email belongs to the user
        if ($request->channel() === OtpChannel::Email && ! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $this->tokenResponse($user, $role, $request->input('device_name'));
    }

    /**
     * Revoke the token used for the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * Add a self-registerable role (e.g. developer) to the signed-in account, so an existing player
     * doesn't need a second account. The current token is swapped for one scoped to the new role.
     */
    public function addRole(Request $request, Role $role): JsonResponse
    {
        abort_unless($role->isSelfRegisterable(), 403, "The {$role->value} role cannot be self-assigned.");

        $user = $request->user();
        $user->assignRole($role->value);
        $request->user()->currentAccessToken()->delete();

        return $this->tokenResponse($user, $role, 'website');
    }

    /**
     * Return the authenticated user.
     */
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('organization'));
    }

    /**
     * Change the signed-in user's name and email.
     */
    public function updateProfile(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();
        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return new UserResource($user->load('organization'));
    }

    /**
     * Turn the signed-in individual account into an organization account with the given details,
     * or change the details of an organization account. Roles are kept as they are.
     */
    public function updateOrganization(UpdateOrganizationRequest $request): UserResource
    {
        $user = $request->user();

        DB::transaction(function () use ($request, $user): void {
            $user->update(['account_type' => AccountType::Organization]);
            $user->organization()->updateOrCreate([], [
                'name' => $request->validated('name'),
                'national_id' => $request->validated('national_id'),
                'phone' => $request->validated('phone'),
            ]);
        });

        return new UserResource($user->load('organization'));
    }

    /**
     * Change the signed-in user's password. Every other session is signed out; this one stays.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['password' => $request->validated('password')]);
        $user->tokens()->whereKeyNot($user->currentAccessToken()->getKey())->delete();

        return response()->json(['message' => 'Password updated.']);
    }

    private function ensureHasRole(User $user, Role $role, string $errorKey): void
    {
        if (! $user->hasRole($role->value)) {
            throw ValidationException::withMessages([
                $errorKey => ["This account does not have the {$role->value} role."],
            ]);
        }
    }

    /**
     * Refuse to sign in an account staff disabled in the admin panel.
     */
    private function ensureNotDisabled(User $user, string $errorKey): void
    {
        if ($user->isDisabled()) {
            throw ValidationException::withMessages([
                $errorKey => [User::DISABLED_MESSAGE],
            ]);
        }
    }

    /**
     * Issue a Sanctum token scoped to the role the user logged in as.
     */
    private function tokenResponse(User $user, Role $role, ?string $deviceName, int $status = 200): JsonResponse
    {
        $token = $user->createToken(
            $deviceName ?: "{$role->value}-token",
            ["role:{$role->value}"],
            now()->addHours(Setting::integer('session_hours')),
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'role' => $role->value,
            'user' => new UserResource($user->load('organization')),
        ], $status);
    }
}
