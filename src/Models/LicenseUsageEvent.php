<?php

namespace Westel\License\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LicenseUsageEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'license_key',
        'event_type',
        'feature_key',
        'action',
        'metadata',
        'ip_address',
        'user_agent',
        'user_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Log a usage event
     */
    public static function logEvent(
        ?string $licenseKey,
        string $eventType,
        ?string $featureKey = null,
        ?string $action = null,
        ?array $metadata = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $userId = null
    ): self {
        return self::create([
            'license_key' => $licenseKey,
            'event_type' => $eventType,
            'feature_key' => $featureKey,
            'action' => $action,
            'metadata' => $metadata,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'user_id' => $userId,
            'created_at' => now(),
        ]);
    }

    /**
     * Get usage statistics for a given period
     */
    public static function getStats(int $days = 30): array
    {
        $startDate = now()->subDays($days);

        $totalEvents = self::where('created_at', '>=', $startDate)->count();

        $apiRequestsToday = self::where('event_type', 'api_request')
            ->whereDate('created_at', now()->toDateString())
            ->count();

        // Get popular features
        $popularFeatures = self::where('created_at', '>=', $startDate)
            ->whereNotNull('feature_key')
            ->selectRaw('feature_key, COUNT(*) as count')
            ->groupBy('feature_key')
            ->orderByDesc('count')
            ->limit(10)
            ->pluck('count', 'feature_key')
            ->toArray();

        // Get daily usage for last 30 days
        $dailyUsage = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $count = self::whereDate('created_at', $date)->count();
            $dailyUsage[$date] = $count;
        }

        return [
            'total_events' => $totalEvents,
            'api_requests_today' => $apiRequestsToday,
            'popular_features' => $popularFeatures,
            'daily_usage' => $dailyUsage,
        ];
    }
}
