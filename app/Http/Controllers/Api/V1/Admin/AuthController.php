<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateAdminProfileRequest;
use App\Http\Requests\Api\V1\AvatarRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\UpdatePasswordRequest;
use App\Http\Resources\AdminResource;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in for staff accounts. Website users cannot sign in here, and staff cannot sign in to the website.
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $admin = Admin::where('email', $request->string('email'))->first();

        if (! $admin || ! Hash::check($request->string('password'), $admin->password)) {
            throw ValidationException::withMessages(['email' => [__('auth.failed')]]);
        }

        $admin->forceFill(['last_login_at' => now()])->save();
        $token = $admin->createToken($request->input('device_name') ?: 'admin-panel', ['admin']);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'admin' => new AdminResource($admin),
        ]);
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
     * The signed-in staff member with their roles and permissions.
     */
    public function me(Request $request): AdminResource
    {
        return new AdminResource($request->user());
    }

    /**
     * Change the signed-in staff member's name and email.
     */
    public function updateProfile(UpdateAdminProfileRequest $request): AdminResource
    {
        $admin = $request->user();
        $admin->update($request->validated());

        return new AdminResource($admin);
    }

    /**
     * Change the signed-in staff member's password. Every other session is signed out; this one stays.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $admin = $request->user();
        $admin->update(['password' => $request->validated('password')]);
        $admin->tokens()->whereKeyNot($admin->currentAccessToken()->getKey())->delete();

        return response()->json(['message' => 'Password updated.']);
    }

    /**
     * Upload a new avatar for the signed-in staff member, replacing the current one.
     */
    public function storeAvatar(AvatarRequest $request): AdminResource
    {
        $admin = $request->user();
        $admin->replaceAvatar($request->file('avatar'));

        return new AdminResource($admin);
    }

    public function destroyAvatar(Request $request): AdminResource
    {
        $admin = $request->user();
        $admin->replaceAvatar(null);

        return new AdminResource($admin);
    }
}
