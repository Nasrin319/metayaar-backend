<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ListingStatus;
use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Counts for the admin panel overview.
     */
    public function __invoke(): JsonResponse
    {
        $listingCounts = Listing::toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'data' => [
                'listings' => collect(ListingStatus::values())
                    ->mapWithKeys(fn (string $status) => [$status => (int) ($listingCounts[$status] ?? 0)]),
                'users' => User::count(),
            ],
        ]);
    }
}
