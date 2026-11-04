<?php

namespace App\Enums;

/**
 * Where a developer's request to be paid out stands.
 */
enum PayoutRequestStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Rejected = 'rejected';
}
