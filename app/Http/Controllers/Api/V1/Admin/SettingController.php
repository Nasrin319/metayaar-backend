<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateSettingsRequest;
use App\Models\OtpLockout;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

/**
 * Site settings, such as how many codes an email or mobile may be sent before it is locked out, and
 * the emails and mobiles locked out right now.
 */
class SettingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => Setting::values()]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        Setting::put($request->validated());

        return response()->json(['data' => Setting::values()]);
    }

    /**
     * Emails and mobiles that may not get a code right now, the soonest to be released first.
     */
    public function lockouts(): JsonResponse
    {
        $lockouts = OtpLockout::query()
            ->where('locked_until', '>', now())
            ->orderBy('locked_until')
            ->limit(200)
            ->get(['id', 'channel', 'destination', 'sends', 'locked_until']);

        return response()->json(['data' => $lockouts]);
    }

    /**
     * Let the email or mobile get codes again straight away.
     */
    public function unlock(OtpLockout $otpLockout): JsonResponse
    {
        $otpLockout->delete();

        return response()->json(null, 204);
    }
}
