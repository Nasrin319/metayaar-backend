<?php

namespace App\Support;

use App\Enums\DownloadSource;
use App\Models\Download;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Download numbers for one app, or for the whole store when no listing is given.
 */
class DownloadStats
{
    public const array PERIODS = [7, 30, 90, 365];

    /**
     * @return array{total: int, unique_users: int, period_days: int, period_total: int, period_unique_users: int, previous_period_total: int, by_source: array<string, int>, daily: list<array{date: string, total: int}>}
     */
    public static function for(?int $listingId, int $days): array
    {
        $since = now()->startOfDay()->subDays($days - 1);
        $previousSince = $since->copy()->subDays($days);
        $scope = fn (): Builder => Download::query()->when($listingId, fn (Builder $query) => $query->where('listing_id', $listingId));

        $daily = $scope()
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as day, count(*) as total')
            ->groupBy(DB::raw('DATE(created_at)'))
            ->pluck('total', 'day');

        $bySource = $scope()
            ->where('created_at', '>=', $since)
            ->selectRaw('source, count(*) as total')
            ->groupBy('source')
            ->pluck('total', 'source');

        return [
            'total' => $scope()->count(),
            'unique_users' => $scope()->distinct()->count('user_id'),
            'period_days' => $days,
            'period_total' => (int) $daily->sum(),
            'period_unique_users' => $scope()->where('created_at', '>=', $since)->distinct()->count('user_id'),
            'previous_period_total' => $scope()->whereBetween('created_at', [$previousSince, $since])->count(),
            'by_source' => collect(DownloadSource::values())->mapWithKeys(fn (string $source) => [$source => (int) ($bySource[$source] ?? 0)])->all(),
            // every day of the period, zeros included, oldest first
            'daily' => collect(range(0, $days - 1))
                ->map(function (int $offset) use ($since, $daily): array {
                    $date = Carbon::parse($since)->addDays($offset)->toDateString();

                    return ['date' => $date, 'total' => (int) ($daily[$date] ?? 0)];
                })
                ->all(),
        ];
    }
}
