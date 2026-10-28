<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Requests\Api\V1\UpdatePasswordRequest;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new account with the given self-registerable role (player or developer).
     */
    public function register(RegisterRequest $request, Role $role): JsonResponse
    {
        abort_unless($role->isSelfRegisterable(), 403, "Registration as {$role->value} is not allowed.");

        $user = DB::transaction(function () use ($request, $role): User {
            $user = User::create($request->safe()->only(['name', 'email', 'password']));
            $user->assignRole($role->value);

            return $user;
        });

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

        if (! $user->hasRole($role->value)) {
            throw ValidationException::withMessages([
                'email' => ["This account does not have the {$role->value} role."],
            ]);
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
        return new UserResource($request->user());
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

        return new UserResource($user);
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

    /**
     * Issue a Sanctum token scoped to the role the user logged in as.
     */
    private function tokenResponse(User $user, Role $role, ?string $deviceName, int $status = 200): JsonResponse
    {
        $token = $user->createToken($deviceName ?: "{$role->value}-token", ["role:{$role->value}"]);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'role' => $role->value,
            'user' => new UserResource($user),
        ], $status);
    }
}
