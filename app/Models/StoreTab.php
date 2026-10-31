<?php

namespace App\Models;

use App\Enums\StoreTabRule;
use Database\Factories\StoreTabFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A filter tab on the store's apps page, managed in the admin panel. Tabs with the same group sit
 * together; the store draws a divider between groups.
 */
#[Fillable(['label', 'slug', 'rule', 'value', 'group', 'position', 'is_active'])]
class StoreTab extends Model
{
    /** @use HasFactory<StoreTabFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (StoreTab $tab): void {
            if (! $tab->rule->needsValue()) {
                $tab->value = null;
            }
            // A slug the admin typed is validated as unique; one derived from the rule may need a suffix.
            $tab->slug = Str::slug((string) $tab->slug) ?: static::uniqueSlug($tab->value ?: $tab->rule->value, $tab->id);
        });
    }

    private static function uniqueSlug(string $source, ?int $ignoreId): string
    {
        $base = Str::slug($source) ?: 'tab';
        $slug = $base;

        while (static::where('slug', $slug)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rule' => StoreTabRule::class,
            'group' => 'integer',
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Tabs in the order the store shows them.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('group')->orderBy('position')->orderBy('id');
    }
}
