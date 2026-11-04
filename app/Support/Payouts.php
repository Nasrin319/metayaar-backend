<?php

namespace App\Support;

use App\Enums\PayoutRequestStatus;
use App\Enums\Permission;
use App\Enums\WalletTransactionType;
use App\Models\Admin;
use App\Models\PayoutRequest;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PayoutRequestReviewed;
use App\Notifications\PayoutRequested;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paying developers from their wallet. A sale can be requested payout_hold_days after the purchase, a
 * developer has one open request at a time, and staff pay it by hand (card to card) and mark it paid.
 */
class Payouts
{
    public function __construct(private Sales $sales) {}

    /**
     * What the developer's wallet holds and how much of it can be requested now.
     *
     * @return array{balance: int, held: int, pending: int, available: int, hold_days: int}
     */
    public function summary(User $developer): array
    {
        $holdDays = Setting::integer('payout_hold_days');
        $held = $holdDays === 0 ? 0 : (int) $developer->walletTransactions()
            ->where('type', WalletTransactionType::Sale)
            ->where('created_at', '>', now()->subDays($holdDays))
            ->sum('amount');
        $pending = (int) $developer->payoutRequests()->where('status', PayoutRequestStatus::Pending)->sum('amount');

        return [
            'balance' => $developer->wallet_balance,
            'held' => $held,
            'pending' => $pending,
            'available' => max(0, $developer->wallet_balance - $held - $pending),
            'hold_days' => $holdDays,
        ];
    }

    public function request(User $developer, int $amount): PayoutRequest
    {
        $request = DB::transaction(function () use ($developer, $amount): PayoutRequest {
            // one at a time per developer, so two requests can't both pass the check below
            $locked = User::query()->lockForUpdate()->findOrFail($developer->id);
            $account = $locked->payoutAccount;

            if (! $account) {
                throw ValidationException::withMessages(['amount' => ['ابتدا اطلاعات بانکی خود را در پروفایل ثبت کنید.']]);
            }
            if ($locked->payoutRequests()->where('status', PayoutRequestStatus::Pending)->exists()) {
                throw ValidationException::withMessages(['amount' => ['یک درخواست تسویه در حال بررسی دارید؛ بعد از بررسی آن می‌توانید درخواست جدید ثبت کنید.']]);
            }
            if ($amount > $this->summary($locked)['available']) {
                throw ValidationException::withMessages(['amount' => ['مبلغ درخواستی بیشتر از موجودی قابل برداشت است.']]);
            }

            return $locked->payoutRequests()->create([
                'amount' => $amount,
                ...$account->only(['holder_name', 'bank_name', 'sheba', 'card_number']),
            ]);
        });

        StaffNotifications::send(Permission::ManageFinance, new PayoutRequested($request));

        return $request;
    }

    /**
     * The money was sent: take it out of the wallet and tell the developer.
     */
    public function markPaid(PayoutRequest $request, Admin $admin, ?string $reference): PayoutRequest
    {
        $paid = DB::transaction(function () use ($request, $admin, $reference): PayoutRequest {
            $locked = $this->lockPending($request);

            $transaction = $this->sales->credit(
                $locked->developer,
                -$locked->amount,
                WalletTransactionType::Payout,
                description: "تسویه درخواست شماره {$locked->id}",
            );

            $locked->forceFill([
                'status' => PayoutRequestStatus::Paid,
                'reference' => $reference,
                'wallet_transaction_id' => $transaction->id,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            return $locked;
        });

        $this->reviewed($paid);

        return $paid;
    }

    public function reject(PayoutRequest $request, Admin $admin, string $reason): PayoutRequest
    {
        $rejected = DB::transaction(function () use ($request, $admin, $reason): PayoutRequest {
            $locked = $this->lockPending($request);

            $locked->forceFill([
                'status' => PayoutRequestStatus::Rejected,
                'rejection_reason' => $reason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            return $locked;
        });

        $this->reviewed($rejected);

        return $rejected;
    }

    /**
     * The row is locked so two staff members can't both pay the same request.
     */
    private function lockPending(PayoutRequest $request): PayoutRequest
    {
        $locked = PayoutRequest::query()->lockForUpdate()->findOrFail($request->id);

        abort_unless($locked->status === PayoutRequestStatus::Pending, 409, 'این درخواست قبلاً بررسی شده است.');

        return $locked;
    }

    private function reviewed(PayoutRequest $request): void
    {
        $request->developer->notify(new PayoutRequestReviewed($request));
        StaffNotifications::resolve('payout_request_id', $request->id);
    }
}
