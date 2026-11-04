<?php

namespace App\Http\Controllers\Api\V1\Developer;

use App\Http\Controllers\Controller;
use App\Models\PayoutRequest;
use App\Support\Payouts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in developer's requests to be paid from their wallet.
 */
class PayoutRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $requests = $request->user()->payoutRequests()->latest('id')->paginate(10);

        return response()->json([
            'data' => $requests->getCollection()->map($this->present(...)),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function store(Request $request, Payouts $payouts): JsonResponse
    {
        $data = $request->validate(
            ['amount' => ['required', 'integer', 'min:1']],
            ['amount.*' => 'مبلغ را به تومان و به صورت عدد وارد کنید.'],
        );

        return response()->json(['data' => $this->present($payouts->request($request->user(), $data['amount']))], 201);
    }

    /**
     * Only the end of the card or Sheba number, enough to recognise the account.
     *
     * @return array<string, mixed>
     */
    private function present(PayoutRequest $payout): array
    {
        return [
            'id' => $payout->id,
            'amount' => $payout->amount,
            'status' => $payout->status,
            'destination' => substr((string) ($payout->card_number ?: $payout->sheba), -4),
            'reference' => $payout->reference,
            'rejection_reason' => $payout->rejection_reason,
            'reviewed_at' => $payout->reviewed_at,
            'created_at' => $payout->created_at,
        ];
    }
}
