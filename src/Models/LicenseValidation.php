<?php

namespace Westel\License\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseValidation extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'license_id',
        'license_key',
        'hardware_fingerprint',
        'validation_type',
        'result',
        'ip_address',
        'user_agent',
        'context',
        'validated_at',
    ];

    protected $casts = [
        'validated_at' => 'datetime',
        'context' => 'array',
    ];

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public static function logValidation(
        ?string $licenseKey,
        string $hardwareFingerprint,
        string $validationType,
        string $result,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?array $context = null
    ): self {
        return self::create([
            'license_key' => $licenseKey,
            'hardware_fingerprint' => $hardwareFingerprint,
            'validation_type' => $validationType,
            'result' => $result,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'context' => $context,
            'validated_at' => now(),
        ]);
    }

    public function isSuccessful(): bool
    {
        return $this->result === 'success';
    }

    public function isFailed(): bool
    {
        return !$this->isSuccessful();
    }

    /**
     * Get validation statistics for a given period
     */
    public static function getStats(int $days = 30): array
    {
        $startDate = now()->subDays($days);

        $totalValidations = self::where('validated_at', '>=', $startDate)->count();
        $successfulValidations = self::where('validated_at', '>=', $startDate)
            ->where('result', 'success')
            ->count();
        $failedValidations = self::where('validated_at', '>=', $startDate)
            ->where('result', 'failed')
            ->count();

        $successRate = $totalValidations > 0
            ? ($successfulValidations / $totalValidations) * 100
            : 0;

        $lastValidation = self::latest('validated_at')->first();

        $activeDevices = self::where('validated_at', '>=', $startDate)
            ->where('result', 'success')
            ->distinct('hardware_fingerprint')
            ->count('hardware_fingerprint');

        return [
            'total_validations_30d' => $totalValidations,
            'successful_validations_30d' => $successfulValidations,
            'failed_validations_30d' => $failedValidations,
            'success_rate_30d' => round($successRate, 2),
            'last_validation_at' => $lastValidation?->validated_at?->toISOString(),
            'active_devices' => $activeDevices,
        ];
    }
}
