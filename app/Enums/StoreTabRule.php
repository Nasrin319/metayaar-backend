<?php

namespace App\Enums;

use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Which published listings a store tab shows. Category and tag rules match the tab's value.
 */
enum StoreTabRule: string
{
    case All = 'all';
    case Free = 'free';
    case EarlyAccess = 'early_access';
    case Category = 'category';
    case Tag = 'tag';

    public function needsValue(): bool
    {
        return $this === self::Category || $this === self::Tag;
    }

    /**
     * Narrow a listings query to the listings this rule matches.
     */
    public function apply(Builder $query, ?string $value): Builder
    {
        return match ($this) {
            self::All => $query,
            self::Free => $query->where('price', 0),
            self::EarlyAccess => $query->where('early_access', true),
            self::Category => $query->where('category', $value),
            self::Tag => $query->whereJsonContains('tags', $value),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
