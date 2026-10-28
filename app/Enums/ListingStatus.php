<?php

namespace App\Enums;

/**
 * Review lifecycle of a listing (spec DEV-2): draft → in review → published or rejected.
 */
enum ListingStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Published = 'published';
    case Rejected = 'rejected';

    /**
     * Whether the developer may submit the listing for review from this status.
     */
    public function canBeSubmitted(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    /**
     * Whether a reviewer may approve or reject the listing from this status.
     */
    public function canBeReviewed(): bool
    {
        return $this === self::InReview;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
