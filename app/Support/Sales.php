<?php

namespace App\Support;

use App\Enums\WalletTransactionType;
use App\Models\Listing;
use App\Models\Purchase;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records a sale of a paid listing: the buyer's purchase, Metayaar's commission, and the rest credited
 * to the developer's wallet. The payment flow calls record() once the payment is verified.
 */
class Sales
{
    public function record(User $buyer, Listing $listing): Purchase
    {
        if ($listing->price <= 0) {
            throw new InvalidArgumentException('Free listings are not sold.');
        }

        return DB::transaction(function () use ($buyer, $listing): Purchase {
            $percent = $listing->commissionPercent();
            $amount = $listing->price;
            $commission = (int) round($amount * $percent / 100);

            $purchase = new Purchase([
                'listing_id' => $listing->id,
                'amount' => $amount,
                'commission_percent' => $percent,
                'commission_amount' => $commission,
                'developer_amount' => $amount - $commission,
            ]);
            $purchase->user()->associate($buyer)->save();

            $this->credit(
                $listing->developer,
                $purchase->developer_amount,
                WalletTransactionType::Sale,
                purchase: $purchase,
                listing: $listing,
                description: "فروش «{$listing->title}» (کارمزد متایار {$percent}٪)",
            );

            return $purchase;
        });
    }

    /**
     * Change the wallet balance and write the transaction; a negative amount takes money out.
     * The user row is locked so concurrent sales can't lose an update.
     */
    public function credit(
        User $user,
        int $amount,
        WalletTransactionType $type,
        ?Purchase $purchase = null,
        ?Listing $listing = null,
        ?string $description = null,
    ): WalletTransaction {
        return DB::transaction(function () use ($user, $amount, $type, $purchase, $listing, $description): WalletTransaction {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $locked->forceFill(['wallet_balance' => $locked->wallet_balance + $amount])->save();
            $user->wallet_balance = $locked->wallet_balance;

            return $locked->walletTransactions()->create([
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $locked->wallet_balance,
                'purchase_id' => $purchase?->id,
                'listing_id' => $listing?->id,
                'description' => $description,
            ]);
        });
    }
}
