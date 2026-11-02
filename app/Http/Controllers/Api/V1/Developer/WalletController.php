<?php

namespace App\Http\Controllers\Api\V1\Developer;

use App\Enums\WalletTransactionType;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in developer's wallet: the balance from their sales and every change to it.
 */
class WalletController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $sales = $user->walletTransactions()->where('type', WalletTransactionType::Sale)
            ->join('purchases', 'purchases.id', '=', 'wallet_transactions.purchase_id')
            ->selectRaw('count(*) as sales_count, coalesce(sum(purchases.amount), 0) as gross, coalesce(sum(purchases.commission_amount), 0) as commission, coalesce(sum(purchases.developer_amount), 0) as earned')
            ->first();

        $transactions = $user->walletTransactions()
            ->with(['listing:id,title,slug', 'purchase:id,amount,commission_percent,commission_amount'])
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'balance' => $user->wallet_balance,
            'totals' => [
                'sales_count' => (int) $sales->sales_count,
                'gross' => (int) $sales->gross,
                'commission' => (int) $sales->commission,
                'earned' => (int) $sales->earned,
            ],
            'data' => $transactions->items(),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }
}
