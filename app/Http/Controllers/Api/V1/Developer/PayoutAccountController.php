<?php

namespace App\Http\Controllers\Api\V1\Developer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Developer\PayoutAccountRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in developer's payout details, required before a paid listing goes to review.
 */
class PayoutAccountController extends Controller
{
    /**
     * The payout details, or null when none were saved yet.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->payoutAccount]);
    }

    public function update(PayoutAccountRequest $request): JsonResponse
    {
        $account = $request->user()->payoutAccount()->updateOrCreate([], $request->validated());

        return response()->json(['data' => $account]);
    }
}
