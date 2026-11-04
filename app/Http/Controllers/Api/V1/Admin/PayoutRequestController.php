<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PayoutRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\PayoutRequest;
use App\Support\Payouts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Developers' payout requests as finance staff handle them: send the money by hand, then mark it paid.
 */
class PayoutRequestController extends Controller
{
    /**
     * Oldest first while waiting, so nobody waits longest by accident; newest first otherwise.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::enum(PayoutRequestStatus::class)]]);
        $status = PayoutRequestStatus::tryFrom($data['status'] ?? PayoutRequestStatus::Pending->value);

        $requests = PayoutRequest::query()
            ->with('developer:id,name,email,wallet_balance')
            ->where('status', $status)
            ->orderBy('id', $status === PayoutRequestStatus::Pending ? 'asc' : 'desc')
            ->paginate(20);

        return response()->json([
            'data' => $requests->getCollection()->map($this->present(...)),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'total' => $requests->total(),
            ],
            'pending_count' => PayoutRequest::query()->where('status', PayoutRequestStatus::Pending)->count(),
        ]);
    }

    public function pay(Request $request, PayoutRequest $payoutRequest, Payouts $payouts): JsonResponse
    {
        $data = $request->validate(
            ['reference' => ['nullable', 'string', 'max:100']],
            ['reference.*' => 'کد پیگیری نباید بیشتر از ۱۰۰ نویسه باشد.'],
        );

        return response()->json(['data' => $this->present($payouts->markPaid($payoutRequest, $request->user(), $data['reference'] ?? null))]);
    }

    public function reject(Request $request, PayoutRequest $payoutRequest, Payouts $payouts): JsonResponse
    {
        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:500']],
            ['reason.*' => 'دلیل رد درخواست را در حداکثر ۵۰۰ نویسه بنویسید.'],
        );

        return response()->json(['data' => $this->present($payouts->reject($payoutRequest, $request->user(), $data['reason']))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PayoutRequest $payout): array
    {
        return [
            'id' => $payout->id,
            'amount' => $payout->amount,
            'status' => $payout->status,
            'developer' => $payout->developer->only(['id', 'name', 'email', 'wallet_balance']),
            'holder_name' => $payout->holder_name,
            'bank_name' => $payout->bank_name,
            'sheba' => $payout->sheba,
            'card_number' => $payout->card_number,
            'reference' => $payout->reference,
            'rejection_reason' => $payout->rejection_reason,
            'reviewed_at' => $payout->reviewed_at,
            'created_at' => $payout->created_at,
        ];
    }
}
