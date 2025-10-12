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
        ?string $licenseId,
        string $hardwareFingerprint,
        string $validationType,
        string $result,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?array $context = null
    ): ?self {
        // Skip logging if license_id is null since the database constraint doesn't allow it
        if (!$licenseId) {
            \Log::info('Skipping license validation log: license_id is null', [
                'hardware_fingerprint' => $hardwareFingerprint,
                'validation_type' => $validationType,
                'result' => $result,
                'ip_address' => $ipAddress,
            ]);
            return null;
        }

        return self::create([
            'license_id' => $licenseId,
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
}
