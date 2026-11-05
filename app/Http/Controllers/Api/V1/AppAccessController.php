<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AppAccessController extends Controller
{
    /**
     * What the signed-in user may do with a listing, so the store page can offer "download" instead of "buy".
     */
    public function __invoke(Request $request, Listing $listing): JsonResponse
    {
        // nothing about a private listing is told to people who weren't invited
        abort_if($listing->is_private && $listing->user_id !== $request->user()->id && ! $listing->isAvailableTo($request->user()), 404);

        return response()->json([
            'data' => [
                'can_download' => Gate::inspect('download', $listing)->allowed(),
                'is_owner' => $listing->user_id === $request->user()->id,
            ],
        ]);
    }
}
