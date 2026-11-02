<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Models\Purchase;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sales money: Metayaar's commission (the default and per listing), what developers earned, and
 * what their wallets hold.
 */
class FinanceController extends Controller
{
    public function show(): JsonResponse
    {
        $totals = Purchase::query()
            ->selectRaw('count(*) as sales_count, coalesce(sum(amount), 0) as gross, coalesce(sum(commission_amount), 0) as commission, coalesce(sum(developer_amount), 0) as developer_share')
            ->first();

        $developers = User::query()
            ->where('wallet_balance', '!=', 0)
            ->orderByDesc('wallet_balance')
            ->limit(50)
            ->get(['id', 'name', 'email', 'wallet_balance']);

        $recentSales = Purchase::query()
            ->with(['listing:id,title,user_id', 'listing.developer:id,name', 'user:id,name'])
            ->latest('id')
            ->limit(20)
            ->get();

        return response()->json(['data' => [
            'default_commission_percent' => Setting::integer('default_commission_percent'),
            'totals' => [
                'sales_count' => (int) $totals->sales_count,
                'gross' => (int) $totals->gross,
                'commission' => (int) $totals->commission,
                'developer_share' => (int) $totals->developer_share,
                'wallet_balances' => (int) User::query()->sum('wallet_balance'),
            ],
            'developers' => $developers,
            'recent_sales' => $recentSales->map(fn (Purchase $purchase): array => [
                'id' => $purchase->id,
                'listing' => $purchase->listing?->only(['id', 'title']),
                'developer' => $purchase->listing?->developer?->only(['id', 'name']),
                'buyer' => $purchase->user?->only(['id', 'name']),
                'amount' => $purchase->amount,
                'commission_percent' => $purchase->commission_percent,
                'commission_amount' => $purchase->commission_amount,
                'developer_amount' => $purchase->developer_amount,
                'created_at' => $purchase->created_at,
            ]),
        ]]);
    }

    /**
     * The commission for listings without their own. Sales already made keep the percent they had.
     */
    public function updateDefaultCommission(Request $request): JsonResponse
    {
        $data = $request->validate([
            'default_commission_percent' => ['required', 'integer', 'min:0', 'max:100'],
        ], ['default_commission_percent.*' => 'درصد کارمزد باید عددی بین ۰ تا ۱۰۰ باشد.']);

        Setting::put($data);

        return $this->show();
    }

    /**
     * Give one listing its own commission percent, or null to use the default again.
     */
    public function updateListingCommission(Request $request, Listing $listing): ListingResource
    {
        $data = $request->validate([
            'commission_percent' => ['present', 'nullable', 'integer', 'min:0', 'max:100'],
        ], ['commission_percent.*' => 'درصد کارمزد باید عددی بین ۰ تا ۱۰۰ باشد.']);

        $listing->forceFill(['commission_percent' => $data['commission_percent']])->save();

        return new ListingResource($listing->load('developer.payoutAccount'));
    }
}
