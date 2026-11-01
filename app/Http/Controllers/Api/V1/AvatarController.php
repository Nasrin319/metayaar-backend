<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AvatarRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

/**
 * The signed-in user's profile picture.
 */
class AvatarController extends Controller
{
    /**
     * Upload a new avatar, replacing the current one.
     */
    public function store(AvatarRequest $request): UserResource
    {
        $user = $request->user();
        $user->replaceAvatar($request->file('avatar'));

        return new UserResource($user->load('organization'));
    }

    /**
     * Remove the avatar.
     */
    public function destroy(Request $request): UserResource
    {
        $user = $request->user();
        $user->replaceAvatar(null);

        return new UserResource($user->load('organization'));
    }
}
