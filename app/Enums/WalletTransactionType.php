<?php

namespace App\Enums;

/**
 * Why a developer's wallet balance changed.
 */
enum WalletTransactionType: string
{
    /** A player bought one of the developer's apps: the price minus Metayaar's commission. */
    case Sale = 'sale';

    /** Metayaar paid money out to the developer's bank account. */
    case Payout = 'payout';
}
