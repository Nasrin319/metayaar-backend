<?php

namespace App\Enums;

/**
 * Where an email on a private listing's list stands. Only "active" opens the listing.
 */
enum ListingInviteStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case Rejected = 'rejected';
}
